<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\School;
use App\Support\Migration\ImportFingerprint;
use App\Support\Migration\LegacyWorkbook;
use App\Support\Migration\ProductionImportAuthorization;
use App\Support\Migration\StudentImportApply;
use App\Support\Migration\StudentImportPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M5 — impor siswa ke basis data **produksi**.
 *
 * Perintah tersendiri, dan namanya menyebut apa adanya. `migrasi:terapkan-uji`
 * tidak diberi opsi `--produksi`: satu bendera yang salah ketik tidak boleh
 * memisahkan basis data uji dari basis data sekolah, dan dua nama yang berbeda
 * tidak dapat tertukar oleh riwayat shell (butir 522).
 *
 * Yang dibuat batch ini **kemampuan**, bukan **izin**. Perintah ini ada supaya
 * impor produksi kelak dapat dijalankan tanpa mengubah satu baris kode; apakah
 * ia boleh dijalankan, kapan, dan oleh siapa adalah keputusan terpisah yang
 * belum diambil.
 *
 * Tujuh pagar, seluruhnya harus terbuka bersamaan:
 *
 *   1. `APP_ENV=production` — di luar itu perintah menolak dan menyarankan
 *      `migrasi:terapkan-uji`;
 *   2. cabang, tahun ajaran, dan berkas sumber disebut eksplisit;
 *   3. analisis kering dijalankan perintah ini sendiri, tepat sebelum menulis;
 *   4. rekonsiliasi bersih — nol ditolak, nol NIS/NISN ganda, nol NISN tidak
 *      sah, nol CLASS_NOT_FOUND, nol CLASS_AMBIGUOUS;
 *   5. sidik jari yang dicatat dari analisis kering sebelumnya cocok;
 *   6. `--backup-terverifikasi` dan `--konfirmasi`;
 *   7. kalimat konfirmasi diketik utuh, dan kalimatnya memuat jumlah, cabang,
 *      serta tahun ajaran yang sebenarnya.
 *
 * Tidak ada bendera yang dapat melewati satu pun di antaranya, dan tidak ada
 * mode non-interaktif. Bila kelak dibutuhkan, itu keputusan tersendiri — bukan
 * jalan pintas yang ditinggalkan di sini.
 *
 * Kode keluar: 0 selesai, 1 ditolak pagar, 2 gagal dijalankan.
 */
class MigrasiTerapkanProduksi extends Command
{
    protected $signature = 'migrasi:terapkan-produksi
        {berkas : Jalur berkas .xlsx sekolah (di luar repositori)}
        {--school= : Kode cabang tujuan. WAJIB, tanpa nilai bawaan.}
        {--tahun-ajaran= : Nama tahun ajaran tujuan. WAJIB, tidak pernah ditebak.}
        {--sheet-siswa= : Nama lembar siswa, dipisah koma; kosong = deteksi otomatis}
        {--sidik-jari= : Sidik jari dari analisis kering yang sudah ditinjau.}
        {--backup-terverifikasi : Menyatakan operator sudah memverifikasi sendiri backup yang dapat dipulihkan.}
        {--konfirmasi : Melanjutkan ke konfirmasi akhir. Tanpa ini perintah hanya menampilkan pratinjau.}';

    protected $description = 'IMPOR PRODUKSI siswa. Butuh APP_ENV=production, backup terverifikasi, dan konfirmasi diketik.';

    public function handle(): int
    {
        $write = (bool) $this->option('konfirmasi');

        $school = $this->resolveSchool();

        if ($school === null) {
            return 2;
        }

        $year = $this->resolveYear($school);

        if ($year === false) {
            return 2;
        }

        $path = (string) $this->argument('berkas');

        try {
            $workbook = new LegacyWorkbook($path);
            $sheets = $this->resolveSheets($workbook);

            if ($sheets === []) {
                $this->components->error('Tidak ada lembar siswa yang dikenali di berkas ini.');

                return 2;
            }

            // Pagar 3: analisis keringnya dijalankan di sini, atas berkas ini,
            // tepat sebelum menulis. Tidak ada rencana yang dibawa dari
            // pemanggilan lain.
            $plan = (new StudentImportPlan($school, $year))
                ->forSource($path)
                ->build($workbook->students($sheets)['rows']);

            $fingerprint = ImportFingerprint::of($path, $school, $year, $plan);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->renderPreview($school, $year, $plan, $fingerprint, $sheets);

        $refusals = ProductionImportAuthorization::refusals($plan, $year);

        if ($refusals !== []) {
            $this->line('');
            $this->components->error('IMPOR PRODUKSI DITOLAK:');
            $this->components->bulletList($refusals);

            return 1;
        }

        if (! $write) {
            $this->line('');
            $this->components->warn(
                'PRATINJAU — tidak ada satu baris pun yang ditulis. Tinjau angka di atas, '
                .'catat sidik jarinya, lalu jalankan ulang dengan --sidik-jari, '
                .'--backup-terverifikasi, dan --konfirmasi.'
            );

            return 0;
        }

        $gate = $this->confirmationRefusals($fingerprint);

        if ($gate !== []) {
            $this->line('');
            $this->components->error('IMPOR PRODUKSI DITOLAK:');
            $this->components->bulletList($gate);

            return 1;
        }

        $authorization = ProductionImportAuthorization::grant($plan, $school, $year, $fingerprint);

        if ($authorization === null) {
            $this->components->error('Otorisasi tidak dapat diterbitkan.');

            return 1;
        }

        if (! $this->confirmPhrase($authorization)) {
            return 1;
        }

        try {
            $result = (new StudentImportApply($school, $year))
                ->authorizedForProduction($authorization)
                ->run($plan);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $this->recordAudit($school, $year, $plan, $fingerprint, $result);
        $this->renderResult($result, $plan);

        return 0;
    }

    // ================================================== pagar

    /**
     * @return array<int, string>
     */
    protected function confirmationRefusals(ImportFingerprint $fingerprint): array
    {
        $out = [];

        // Pagar 6a. Perintah tidak dapat memeriksa keberadaan backup, dan tidak
        // berpura-pura bisa. Yang diminta pernyataan operator bahwa ia sudah
        // memverifikasinya sendiri — tanggung jawabnya pindah ke orang yang
        // memang dapat memikulnya (butir 523).
        if (! $this->option('backup-terverifikasi')) {
            $out[] = '--backup-terverifikasi belum diberikan. Bendera ini berarti Anda sudah '
                .'memverifikasi sendiri adanya salinan basis data yang benar-benar dapat '
                .'dipulihkan. Perintah ini TIDAK memeriksanya dan tidak dapat memeriksanya.';
        }

        // Pagar 5.
        $typed = trim((string) $this->option('sidik-jari'));

        if ($typed === '') {
            $out[] = '--sidik-jari belum diberikan. Jalankan pratinjau lebih dulu, tinjau '
                .'angkanya, lalu salin sidik jari yang tercetak.';
        } elseif (! $fingerprint->matches($typed)) {
            $out[] = 'Sidik jari tidak cocok. Berkas, cabang, tahun ajaran, atau isi basis data '
                .'sudah berubah sejak analisis kering yang Anda tinjau. Ulangi pratinjaunya.';
        }

        return $out;
    }

    /**
     * Pagar 7 — kalimat konfirmasi diketik utuh.
     */
    protected function confirmPhrase(ProductionImportAuthorization $authorization): bool
    {
        $phrase = $authorization->confirmationPhrase();

        $this->line('');
        $this->components->warn('Ini menulis ke basis data produksi. Ketik kalimat berikut persis:');
        $this->line('');
        $this->line("    {$phrase}");
        $this->line('');

        $typed = (string) $this->ask('Kalimat konfirmasi');

        if (! $authorization->phraseMatches($typed)) {
            $this->components->error('Kalimat tidak cocok. Tidak ada yang ditulis.');

            return false;
        }

        return true;
    }

    // ================================================== tampilan

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, string>  $sheets
     */
    protected function renderPreview(
        School $school,
        ?AcademicYear $year,
        array $plan,
        ImportFingerprint $fingerprint,
        array $sheets,
    ): void {
        $r = $plan['reconciliation'];

        $this->line('');
        $this->components->info('SASARAN');
        $this->components->twoColumnDetail('LINGKUNGAN', $this->environmentLabel());
        $this->components->twoColumnDetail('BASIS DATA', DB::connection()->getDatabaseName() ?? '-');
        $this->components->twoColumnDetail('CABANG', $school->code.' — '.$school->name);
        $this->components->twoColumnDetail(
            'TAHUN AJARAN',
            ($year?->name ?? '-').' (semester '.($year?->semester ?? '?').')',
        );
        // Nama berkasnya saja, tanpa jalur: jalurnya privat.
        $this->components->twoColumnDetail('BERKAS SUMBER', $fingerprint->fileName);
        $this->components->twoColumnDetail('LEMBAR', implode(', ', $sheets));
        $this->components->twoColumnDetail('SIDIK JARI', "<fg=yellow>{$fingerprint->value}</>");

        $this->line('');
        $this->components->info('REKONSILIASI');
        $this->components->twoColumnDetail('SUMBER', (string) $r['source']);
        $this->components->twoColumnDetail('SIAP', "<fg=green>{$r['ready']}</>");
        $this->components->twoColumnDetail('TERTUNDA', $this->pendingLabel($plan));
        $this->components->twoColumnDetail(
            'DITOLAK',
            $r['rejected'] > 0 ? "<fg=red>{$r['rejected']}</>" : '0',
        );

        $this->line('');
        $this->components->info('NISN');

        foreach ($plan['nisn'] as $state => $count) {
            $this->components->twoColumnDetail($state, (string) $count);
        }

        $this->line('');
        $this->components->info('PENEMPATAN KELAS');

        foreach (['PLACEMENT_READY', 'PLACEMENT_EXISTS', 'CLASS_NOT_FOUND', 'CLASS_AMBIGUOUS'] as $state) {
            $count = $plan['placements'][$state] ?? 0;
            $bad = $count > 0 && in_array($state, ['CLASS_NOT_FOUND', 'CLASS_AMBIGUOUS'], true);
            $this->components->twoColumnDetail($state, $bad ? "<fg=red>{$count}</>" : (string) $count);
        }

        $this->line('');
        $this->components->info('YANG AKAN DITULIS');
        $this->components->twoColumnDetail(
            'BUAT BARU',
            (string) ($plan['outcomes'][StudentImportPlan::READY_CREATE] ?? 0),
        );
        $this->components->twoColumnDetail(
            'COCOKKAN (SUDAH ADA)',
            (string) ($plan['outcomes'][StudentImportPlan::READY_MATCH] ?? 0),
        );
        $this->components->twoColumnDetail('AKUN PENGGUNA', '<fg=gray>0 — impor ini tidak pernah membuat akun</>');
    }

    /**
     * Baris tertunda ditampilkan menonjol, bukan disembunyikan di antara angka
     * lain: satu siswa yang tidak ikut adalah hal yang harus disadari sebelum
     * menekan enter, bukan ditemukan sesudahnya (butir 524).
     *
     * @param  array<string, mixed>  $plan
     */
    protected function pendingLabel(array $plan): string
    {
        $pending = (int) $plan['reconciliation']['pending'];

        if ($pending === 0) {
            return '0';
        }

        $reasons = [];

        foreach ($plan['outcomes'] as $outcome => $count) {
            if (str_starts_with((string) $outcome, 'PENDING') || $outcome === StudentImportPlan::PENDING_PPDB_RECONCILIATION) {
                $reasons[] = "{$outcome}={$count}";
            }
        }

        return "<fg=yellow>{$pending} TIDAK IKUT</> — ".implode(', ', $reasons);
    }

    protected function environmentLabel(): string
    {
        $env = app()->environment();

        return $env === 'production' ? "<fg=red>{$env}</>" : "<fg=yellow>{$env}</>";
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $plan
     */
    protected function renderResult(array $result, array $plan): void
    {
        $this->line('');
        $this->components->info('HASIL');
        $this->components->twoColumnDetail('MASTER_IMPORTED', (string) ($result['created'] + $result['matched']));
        $this->components->twoColumnDetail('  dibuat', (string) $result['created']);
        $this->components->twoColumnDetail('  dicocokkan', (string) $result['matched']);
        $this->components->twoColumnDetail('PLACEMENT_IMPORTED', (string) $result['placed']);
        $this->components->twoColumnDetail('ACCOUNT_PENDING', (string) ($result['created'] + $result['matched']));
        $this->components->twoColumnDetail('NISN diisikan', (string) $result['nisn_backfilled']);
        $this->components->twoColumnDetail('NISN berbeda dari tersimpan', (string) count($result['nisn_conflicts']));
        $this->components->twoColumnDetail('dilewati (tertunda)', (string) $result['skipped']);

        $this->line('');
        $this->components->twoColumnDetail(
            'tulis + lewat = sumber',
            $plan['reconciliation']['source'] === $result['created'] + $result['matched'] + $result['skipped']
                ? '<fg=green>seimbang</>'
                : '<fg=red>TIDAK seimbang</>',
        );

        $this->line('');
        $this->components->warn(
            'Akun siswa dan orang tua TIDAK dibuat. Penyediaan akun adalah alur terpisah '
            .'yang menunggu data kontak.'
        );
    }

    // ================================================== jejak audit

    /**
     * Satu baris catatan administratif untuk impor ini.
     *
     * Memakai log aplikasi yang sudah ada, bukan kerangka baru. Baris CUD
     * per-siswa sudah tercatat sendiri di `audit_logs` lewat listener Eloquent,
     * jadi yang kurang hanya catatan agregatnya — dan `audit_logs` tidak punya
     * tempat untuk itu: barisnya selalu menunjuk satu model, dan kolom bebas
     * sengaja tidak pernah ditambahkan supaya tabel itu tidak menjadi salinan
     * data pribadi (butir 45, butir 525).
     *
     * Yang dicatat hanya angka, hash, dan nama yang memang bukan rahasia. Tidak
     * ada nama siswa, NIS, NISN, alamat, maupun isi berkas.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     */
    protected function recordAudit(
        School $school,
        AcademicYear $year,
        array $plan,
        ImportFingerprint $fingerprint,
        array $result,
    ): void {
        Log::info('migrasi siswa produksi diterapkan', [
            'operator_user_id' => Auth::id(),
            'school_code' => $school->code,
            'academic_year' => $year->name,
            'database' => DB::connection()->getDatabaseName(),
            'source_file' => $fingerprint->fileName,
            'source_sha256' => $fingerprint->fileHash,
            'fingerprint' => $fingerprint->value,
            'source_rows' => $plan['reconciliation']['source'],
            'ready' => $plan['reconciliation']['ready'],
            'pending' => $plan['reconciliation']['pending'],
            'rejected' => $plan['reconciliation']['rejected'],
            'created' => $result['created'],
            'matched' => $result['matched'],
            'placed' => $result['placed'],
            'skipped' => $result['skipped'],
            'accounts_created' => 0,
        ]);
    }

    // ================================================== resolusi sasaran

    protected function resolveSchool(): ?School
    {
        $code = trim((string) $this->option('school'));

        if ($code === '') {
            $this->components->error(
                '--school wajib disebut. Perintah impor produksi tidak punya cabang bawaan.'
            );

            return null;
        }

        $school = School::query()->where('code', $code)->first();

        if ($school === null) {
            $this->components->error("Kode cabang tidak ditemukan: {$code}");

            return null;
        }

        return $school;
    }

    /**
     * Tahun ajaran **tidak pernah ditebak** di jalur produksi: tahun yang salah
     * menempatkan seluruh siswa di tahun yang keliru, dan itu baru terlihat pada
     * rapor. Tidak ada jatuh-ke-tahun-aktif seperti di perintah uji.
     */
    protected function resolveYear(School $school): AcademicYear|false|null
    {
        $name = trim((string) $this->option('tahun-ajaran'));

        if ($name === '') {
            $this->components->error(
                '--tahun-ajaran wajib disebut. Perintah ini tidak memakai tahun ajaran aktif '
                .'sebagai bawaan, dan tidak pernah membuat tahun ajaran.'
            );

            return false;
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
}
