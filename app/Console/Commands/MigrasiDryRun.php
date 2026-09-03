<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\School;
use App\Support\Migration\LegacyDryRun;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\NisnNormalizer;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Console\Command;
use Throwable;

/**
 * M2 — analisis kering berkas sekolah. **Tidak ada mode terap.**
 *
 * docs/data-migration-readiness.md §10 merancang `migrasi:siswa` dengan pasangan
 * `--dry-run`/`--terapkan`. Rancangan itu dibuat sebelum berkas sungguhan
 * terlihat. Setelah dilihat, dua hal berubah:
 *
 * 1. Satu berkas memuat siswa **dan** guru pada dua lembar, jadi satu perintah
 *    untuk keduanya lebih jujur daripada dua perintah atas berkas yang sama.
 * 2. `--terapkan` sengaja **tidak** dibuat di sini. Sejak M3 mode terap ada,
 *    tetapi sebagai perintah tersendiri yang berpagar basis data uji —
 *    `migrasi:terapkan-uji`. Memasangnya sebagai opsi pada perintah analisis
 *    akan membuat satu salah ketik memisahkan "melihat" dari "menulis"
 *    (butir 457, diperbarui butir 491).
 *
 * Kode keluar: 0 analisis bersih, 1 ada penghambat, 2 gagal dijalankan.
 */
class MigrasiDryRun extends Command
{
    protected $signature = 'migrasi:dry-run
        {berkas : Jalur berkas .xlsx sekolah (di luar repositori)}
        {--school=PUSAT : Kode cabang, bukan id numerik}
        {--tahun-ajaran= : Nama tahun ajaran tujuan; bawaan: yang sedang aktif}
        {--sheet-siswa= : Nama lembar siswa, boleh lebih dari satu dipisah koma; kosong = deteksi otomatis}
        {--sheet-guru= : Nama lembar guru; kosong = deteksi otomatis}';

    protected $description = 'Menganalisis berkas siswa/guru sekolah terhadap skema, tanpa menulis apa pun.';

    public function handle(): int
    {
        $this->components->warn('MODE KERING — tidak ada satu baris pun yang ditulis ke basis data.');

        $school = School::query()->where('code', $this->option('school'))->first();

        if ($school === null) {
            $this->components->error('Kode cabang tidak ditemukan: '.$this->option('school'));

            return 2;
        }

        $year = $this->resolveYear($school);

        if ($year === false) {
            return 2;
        }

        try {
            $workbook = new LegacyWorkbook((string) $this->argument('berkas'));
            $studentSheets = $this->resolveSheets($workbook, 'sheet-siswa', 'Data Siswa');
            $teacherSheets = $this->resolveSheets($workbook, 'sheet-guru', 'Data Guru');

            $run = new LegacyDryRun($workbook, $school, $year, $studentSheets, $teacherSheets);
            $students = $run->students();
            $teachers = $run->teachers();
            $plan = $run->plan();
        } catch (Throwable $e) {
            // Pesan pengecualian boleh menyebut lembar yang hilang, tidak pernah
            // isi barisnya.
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Cabang</>', $school->code.' — '.$school->name);
        $this->components->twoColumnDetail('<fg=gray>Tahun ajaran tujuan</>', $year?->name ?? '<fg=red>belum ada</>');
        $this->components->twoColumnDetail(
            '<fg=gray>Lembar siswa</>',
            $studentSheets === [] ? '<fg=red>tidak ada</>' : implode(', ', $studentSheets),
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Lembar guru</>',
            $teacherSheets === [] ? '<fg=gray>tidak ada di berkas ini</>' : implode(', ', $teacherSheets),
        );

        $this->renderStudents($students);
        $this->renderPlan($plan);
        $this->renderTeachers($teachers);

        $blockers = $this->blockers($plan, $teachers);

        $this->line('');
        $this->components->info('PERLU DIPUTUSKAN SEBELUM IMPOR UJI (M3) — baris siap tetap boleh masuk');

        if ($blockers === []) {
            $this->components->twoColumnDetail('tidak ada', '<fg=green>siap</>');

            return 0;
        }

        foreach ($blockers as $blocker) {
            $this->components->twoColumnDetail($blocker, '<fg=yellow>tertunda</>');
        }

        return 1;
    }

    protected function resolveYear(School $school): AcademicYear|false|null
    {
        $name = $this->option('tahun-ajaran');

        if ($name === null || $name === '') {
            return AcademicYear::query()
                ->where('school_id', $school->id)
                ->where('is_active', true)
                ->first();
        }

        $matches = AcademicYear::query()
            ->where('school_id', $school->id)
            ->where('name', $name)
            ->get();

        // Nol atau satu, selain itu berhenti (§6.2 butir 2).
        if ($matches->count() > 1) {
            $this->components->error("Tahun ajaran \"{$name}\" ganda di cabang ini; perbaiki datanya lebih dulu.");

            return false;
        }

        if ($matches->isEmpty()) {
            $this->components->error("Tahun ajaran \"{$name}\" tidak ada di cabang ini.");

            return false;
        }

        return $matches->first();
    }

    /**
     * @param  array<string, mixed>  $s
     */
    protected function renderStudents(array $s): void
    {
        $this->line('');
        $this->components->info('SISWA');

        $this->components->twoColumnDetail('baris sumber terbaca', (string) $s['source_rows']);
        $this->components->twoColumnDetail('baris valid', (string) $s['valid_rows']);
        $this->components->twoColumnDetail('kandidat buat baru', (string) $s['create_candidates']);
        $this->components->twoColumnDetail('kandidat cocok (sudah ada)', (string) $s['match_candidates']);
        $this->components->twoColumnDetail('baris ditolak', (string) $s['rejected_rows']);
        $this->components->twoColumnDetail('duplikat NIS di dalam berkas', (string) count($s['duplicates']));
        $this->components->twoColumnDetail('nama kembar (peringatan, bukan identitas)', (string) count($s['name_collisions']));
        $this->components->twoColumnDetail('baris non-data dilewati', (string) $s['ignored_rows']);

        if ($s['missing_required'] !== []) {
            $this->line('');
            $this->components->info('Kolom wajib yang kosong');

            foreach ($s['missing_required'] as $field => $count) {
                $this->components->twoColumnDetail($field, "<fg=red>{$count} baris</>");
            }
        }

        $this->line('');
        $this->components->info('Kesiapan');

        foreach ($s['readiness'] as $state => $count) {
            $colour = $count > 0 && $state !== LegacyDryRun::ACCOUNT_BLOCKED ? 'green' : 'yellow';
            $this->components->twoColumnDetail($state, "<fg={$colour}>{$count}</>");
        }

        foreach ($s['account_blockers'] as $reason => $count) {
            $this->components->twoColumnDetail("  sebab: {$reason}", (string) $count);
        }

        $this->line('');
        $this->components->info('Kandidat penempatan kelas');

        foreach ($s['class_placement'] as $c) {
            $note = $c['blocker'] ?? 'siap';
            $colour = $c['blocker'] === null ? 'green' : 'red';
            $this->components->twoColumnDetail(
                "label \"{$c['label']}\" (tingkat ".($c['grade_level'] ?? '?').", {$c['students']} siswa)",
                "<fg={$colour}>{$note}</>",
            );
        }

        // Angka yang ditulis sekolah sendiri vs angka hasil parsing. Beda berarti
        // parser atau berkasnya salah — dan itu harus terlihat sebelum M3.
        if ($s['declared_totals'] !== []) {
            $this->line('');
            $this->components->info('Pemeriksaan silang terhadap rekap sekolah');

            $declared = array_sum(array_column($s['declared_totals'], 'count'));
            $this->components->twoColumnDetail('jumlah menurut berkas', (string) $declared);
            $this->components->twoColumnDetail(
                'jumlah menurut parser',
                $declared === $s['source_rows']
                    ? "<fg=green>{$s['source_rows']} — cocok</>"
                    : "<fg=red>{$s['source_rows']} — TIDAK cocok</>",
            );
        }

        if ($s['unknown_headings'] !== []) {
            $this->line('');
            $this->components->info('Kolom sumber yang tidak dipetakan (diabaikan dengan aman)');
            $this->components->bulletList($s['unknown_headings']);
        }
    }

    /**
     * @param  array<string, mixed>  $t
     */
    protected function renderTeachers(array $t): void
    {
        $this->line('');
        $this->components->info('GURU / STAF');

        $this->components->twoColumnDetail('baris sumber terbaca', (string) $t['source_rows']);
        $this->components->twoColumnDetail('orang berbeda', (string) $t['distinct_people']);
        $this->components->twoColumnDetail('kandidat buat baru', (string) $t['create_candidates']);
        $this->components->twoColumnDetail('kandidat cocok (sudah ada)', (string) $t['match_candidates']);
        $this->components->twoColumnDetail('nama kembar', (string) count($t['name_collisions']));

        $this->line('');
        $this->components->info('Peran hasil pemilahan');

        foreach ($t['roles'] as $role => $count) {
            $this->components->twoColumnDetail($role, (string) $count);
        }

        $this->line('');
        $this->components->info('Kandidat mata pelajaran');

        foreach ($t['subject_candidates'] as $c) {
            $note = $c['exists_by_name'] ? 'sudah ada' : ($c['code_taken'] ? 'kode bentrok' : 'baru');
            $this->components->twoColumnDetail(
                "{$c['name']}  <fg=gray>kode usulan {$c['proposed_code']}</>",
                "{$c['teachers']} pengampu — {$note}",
            );
        }

        if ($t['non_academic_tokens'] !== []) {
            $this->line('');
            $this->components->info('Bukan mata pelajaran — menunggu keputusan sekolah');

            foreach ($t['non_academic_tokens'] as $token => $count) {
                $this->components->twoColumnDetail($token, "{$count} pengampu");
            }
        }

        if ($t['ambiguous_tokens'] !== []) {
            $this->line('');
            $this->components->info('Token ambigu — TIDAK diklasifikasikan sendiri');

            foreach ($t['ambiguous_tokens'] as $token => $count) {
                $this->components->twoColumnDetail($token, "<fg=yellow>{$count}x — butuh konfirmasi</>");
            }
        }

        $this->line('');
        $this->components->info('Kesiapan akun');

        foreach ($t['readiness'] as $state => $count) {
            $this->components->twoColumnDetail($state, (string) $count);
        }

        foreach ($t['account_blockers'] as $reason => $count) {
            $this->components->twoColumnDetail("  sebab: {$reason}", (string) $count);
        }
    }

    /**
     * Lembar mana yang dibaca.
     *
     * Opsi yang diisi tangan selalu menang, dan nama lembar yang salah ketik
     * tetap melempar galat di pembacanya — supaya "lembarnya tidak ada" tidak
     * pernah menyamar sebagai "isinya nol". Bila opsinya kosong: lembar dengan
     * nama baku dipakai bila ada, selebihnya dideteksi dari isi headingnya
     * (butir 484).
     *
     * @return array<int, string>
     */
    protected function resolveSheets(LegacyWorkbook $workbook, string $option, string $default): array
    {
        $explicit = trim((string) $this->option($option));

        if ($explicit !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $explicit))));
        }

        if ($workbook->hasSheet($default)) {
            return [$default];
        }

        return $option === 'sheet-siswa'
            ? $workbook->detectStudentSheets()
            : $workbook->detectTeacherSheets();
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function renderPlan(array $plan): void
    {
        $this->line('');
        $this->components->info('RENCANA IMPOR M3 — keputusan per baris');

        foreach ($plan['outcomes'] as $outcome => $count) {
            $colour = str_starts_with($outcome, 'READY') ? 'green' : 'yellow';
            $this->components->twoColumnDetail($outcome, "<fg={$colour}>{$count}</>");
        }

        $this->line('');
        $this->components->info('NISN');

        foreach ($plan['nisn'] as $state => $count) {
            $colour = $state === NisnNormalizer::INVALID && $count > 0 ? 'red' : 'gray';
            $this->components->twoColumnDetail($state, "<fg={$colour}>{$count}</>");
        }

        $this->line('');
        $this->components->info('Penempatan kelas untuk baris yang siap');

        foreach ($plan['placements'] as $state => $count) {
            $colour = str_starts_with($state, 'PLACEMENT') ? 'green' : 'red';
            $this->components->twoColumnDetail($state, "<fg={$colour}>{$count}</>");
        }

        foreach ($plan['classes'] as $class) {
            // Koreksi data terkonfirmasi selalu ditampilkan bersama label
            // aslinya: yang membaca laporan berhak tahu nilai mana yang diubah
            // dan menjadi apa (butir 506).
            $name = $class['corrected']
                ? "  label \"{$class['source_label']}\" → \"{$class['label']}\" (koreksi data terkonfirmasi)"
                : "  label \"{$class['label']}\"";

            $this->components->twoColumnDetail(
                $name.' (tingkat '.($class['grade_level'] ?? '?').", {$class['students']} siswa)",
                $class['class_id'] === null ? '<fg=red>rombel belum ada</>' : '<fg=green>cocok</>',
            );
        }

        $r = $plan['reconciliation'];

        $this->line('');
        $this->components->info('Rekonsiliasi');
        $this->components->twoColumnDetail('baris sumber', (string) $r['source']);
        $this->components->twoColumnDetail('siap', "<fg=green>{$r['ready']}</>");
        $this->components->twoColumnDetail('tertunda', "<fg=yellow>{$r['pending']}</>");
        $this->components->twoColumnDetail('ditolak', "<fg=red>{$r['rejected']}</>");
        $this->components->twoColumnDetail(
            'sumber = siap + tertunda + ditolak',
            $r['balanced'] ? '<fg=green>seimbang</>' : '<fg=red>TIDAK seimbang</>',
        );
    }

    /**
     * Yang menuntut keputusan manusia sebelum impor uji.
     *
     * Sejak M3 daftar ini **bukan** lagi daftar "tidak ada yang boleh masuk".
     * Baris yang siap tetap boleh ditulis sekalipun daftar ini terisi; yang
     * dikatakannya hanya bahwa ada baris yang tidak akan ikut (butir 487).
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $t
     * @return array<int, string>
     */
    protected function blockers(array $plan, array $t): array
    {
        $out = [];

        foreach ($plan['outcomes'] as $outcome => $count) {
            if (in_array($outcome, StudentImportPlan::WRITABLE, true)) {
                continue;
            }

            $out[] = "{$outcome}: {$count} baris — tidak ikut impor";
        }

        foreach ($plan['classes'] as $class) {
            if ($class['class_id'] === null) {
                $out[] = "kelas \"{$class['label']}\": rombel belum ada di tahun ajaran tujuan";
            }
        }

        if ($t['ambiguous_tokens'] !== []) {
            $out[] = 'token penugasan ambigu: '.implode(', ', array_keys($t['ambiguous_tokens']));
        }

        $blockedTeachers = $t['readiness'][LegacyDryRun::ACCOUNT_BLOCKED] ?? 0;

        if ($blockedTeachers > 0) {
            $out[] = "akun guru terhambat pada {$blockedTeachers} orang — users.email NOT NULL lagi unik";
        }

        return $out;
    }
}
