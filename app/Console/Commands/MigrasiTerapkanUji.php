<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\School;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\MigrationWriteGuard;
use App\Support\Migration\StudentImportApply;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Console\Command;
use Throwable;

/**
 * M3 — impor uji siswa. **Hanya basis data uji.**
 *
 * Namanya sendiri menyebut batasnya, dan itu disengaja: perintah bernama
 * `migrasi:terapkan` akan cepat dipakai untuk produksi oleh orang yang sedang
 * terburu-buru. Yang ada di sini hanya jalan menuju basis data uji, dan tidak
 * ada opsi yang membuka jalan lain (butir 491).
 *
 * Tanpa `--konfirmasi` perintah ini **tidak menulis apa pun**: ia mencetak
 * rencananya lalu berhenti. Mode kering adalah bawaan, menulis adalah
 * pengecualian yang harus diminta.
 *
 * Ia juga tidak membuat prasyarat. Tahun ajaran dan rombel tujuan harus sudah
 * ada; bila belum, perintah berhenti dan menyebut prasyarat yang kurang.
 * Importer yang membuat rombelnya sendiri akan mengarang nama rombel yang
 * belum pernah dinyatakan sekolah (butir 490).
 *
 * Akun pengguna **tidak** dibuat di sini. Data induk siswa dan penyediaan akun
 * adalah dua pekerjaan terpisah; `students.user_id` boleh tetap null
 * (butir 495).
 *
 * Kode keluar: 0 selesai, 1 ada baris yang tidak ikut, 2 gagal dijalankan.
 */
class MigrasiTerapkanUji extends Command
{
    protected $signature = 'migrasi:terapkan-uji
        {berkas : Jalur berkas .xlsx sekolah (di luar repositori)}
        {--school=PUSAT : Kode cabang, bukan id numerik}
        {--tahun-ajaran= : Nama tahun ajaran tujuan; bawaan: yang sedang aktif}
        {--sheet-siswa= : Nama lembar siswa, boleh lebih dari satu dipisah koma; kosong = deteksi otomatis}
        {--konfirmasi : Tulis ke basis data uji. Tanpa ini perintah hanya mencetak rencana.}';

    protected $description = 'Menerapkan impor siswa ke basis data UJI saja, mengikuti rencana yang sama dengan migrasi:dry-run.';

    public function handle(): int
    {
        $write = (bool) $this->option('konfirmasi');

        if (! $write) {
            $this->components->warn('MODE KERING — tidak ada satu baris pun yang ditulis. Tambahkan --konfirmasi untuk menulis.');
        }

        $refusal = MigrationWriteGuard::refusal();

        if ($write && $refusal !== null) {
            $this->components->error($refusal);
            $this->components->warn('Basis data aktif: '.(MigrationWriteGuard::database() ?? 'tidak terbaca'));

            return 2;
        }

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
            $sheets = $this->resolveSheets($workbook);

            if ($sheets === []) {
                $this->components->error('Tidak ada lembar siswa yang dikenali di berkas ini.');

                return 2;
            }

            $plan = (new StudentImportPlan($school, $year))
                ->build($workbook->students($sheets)['rows']);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Basis data</>', MigrationWriteGuard::database() ?? '-');
        $this->components->twoColumnDetail('<fg=gray>Cabang</>', $school->code.' — '.$school->name);
        $this->components->twoColumnDetail('<fg=gray>Tahun ajaran</>', $year?->name ?? '<fg=red>belum ada</>');
        $this->components->twoColumnDetail('<fg=gray>Lembar siswa</>', implode(', ', $sheets));

        $this->renderPlan($plan);

        if (! $write) {
            return $plan['reconciliation']['ready'] === $plan['reconciliation']['source'] ? 0 : 1;
        }

        try {
            $result = (new StudentImportApply($school, $year))->run($plan);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->renderResult($result, $plan);

        return $plan['reconciliation']['ready'] === $plan['reconciliation']['source'] ? 0 : 1;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveSheets(LegacyWorkbook $workbook): array
    {
        $explicit = trim((string) $this->option('sheet-siswa'));

        if ($explicit !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $explicit))));
        }

        if ($workbook->hasSheet('Data Siswa')) {
            return ['Data Siswa'];
        }

        return $workbook->detectStudentSheets();
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
     * @param  array<string, mixed>  $plan
     */
    protected function renderPlan(array $plan): void
    {
        $this->line('');
        $this->components->info('RENCANA');

        foreach ($plan['outcomes'] as $outcome => $count) {
            $colour = in_array($outcome, StudentImportPlan::WRITABLE, true) ? 'green' : 'yellow';
            $this->components->twoColumnDetail($outcome, "<fg={$colour}>{$count}</>");
        }

        foreach ($plan['nisn'] as $state => $count) {
            $this->components->twoColumnDetail($state, (string) $count);
        }

        foreach ($plan['classes'] as $class) {
            $name = $class['corrected']
                ? "kelas \"{$class['source_label']}\" → \"{$class['label']}\" (koreksi data terkonfirmasi)"
                : "kelas \"{$class['label']}\"";

            $matches = $class['matches'] ?? ($class['class_id'] === null ? 0 : 1);

            $this->components->twoColumnDetail(
                $name." ({$class['students']} siswa)",
                match (true) {
                    $matches > 1 => "<fg=red>CLASS_AMBIGUOUS — {$matches} rombel bernama sama</>",
                    $matches === 0 => '<fg=red>CLASS_NOT_FOUND</>',
                    default => '<fg=green>cocok</>',
                },
            );
        }

        $r = $plan['reconciliation'];
        $this->components->twoColumnDetail(
            'rekonsiliasi',
            "{$r['source']} sumber = {$r['ready']} siap + {$r['pending']} tertunda + {$r['rejected']} ditolak",
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $plan
     */
    protected function renderResult(array $result, array $plan): void
    {
        $this->line('');
        $this->components->info('HASIL TULIS');

        $this->components->twoColumnDetail('siswa dibuat', "<fg=green>{$result['created']}</>");
        $this->components->twoColumnDetail('siswa dicocokkan (sudah ada)', (string) $result['matched']);
        $this->components->twoColumnDetail('NISN diisikan ke baris yang kosong', (string) $result['nisn_backfilled']);
        $this->components->twoColumnDetail('NISN berbeda dari yang tersimpan', (string) count($result['nisn_conflicts']));
        $this->components->twoColumnDetail('penempatan kelas dibuat', (string) $result['placed']);
        $this->components->twoColumnDetail('penempatan dilewati', (string) $result['placement_skipped']);
        $this->components->twoColumnDetail('baris dilewati (tertunda/ditolak)', (string) $result['skipped']);

        $this->line('');
        $this->components->info('AKUN');
        $this->components->twoColumnDetail('MASTER_IMPORTED', (string) ($result['created'] + $result['matched']));
        $this->components->twoColumnDetail('ACCOUNT_READY', '<fg=gray>0 — surel siswa belum terkumpul</>');
        $this->components->twoColumnDetail(
            'ACCOUNT_PENDING',
            (string) ($result['created'] + $result['matched']),
        );

        $this->line('');
        $this->components->twoColumnDetail(
            'tulis + lewat = sumber',
            $plan['reconciliation']['source'] === $result['created'] + $result['matched'] + $result['skipped']
                ? '<fg=green>seimbang</>'
                : '<fg=red>TIDAK seimbang</>',
        );
    }
}
