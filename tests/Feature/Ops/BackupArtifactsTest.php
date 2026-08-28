<?php

namespace Tests\Feature\Ops;

use App\Jobs\GenerateReportCardPdf;
use App\Jobs\GenerateStudentFees;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Artefak operasional: backup, restore, penjadwal, worker.
 *
 * Yang diuji di sini **kontraknya**, bukan isi berkasnya baris demi baris. Test
 * yang menuntut seluruh isi skrip akan jatuh setiap kali komentarnya diperbaiki
 * — dan lama-lama diperbarui tanpa dibaca, yang justru menghapus gunanya.
 *
 * Yang dijaga karena itu hanya sifat yang bila hilang berarti bahaya: tidak ada
 * kredensial yang ter-commit, penghapusan retensi tidak dapat menyentuh apa pun
 * di luar direktori backup, dan pemulihan tidak dapat menimpa basis data
 * sungguhan karena satu salah ketik (butir 370).
 */
class BackupArtifactsTest extends TestCase
{
    use RefreshDatabase;

    protected function backupScript(): string
    {
        return file_get_contents(base_path('ops/backup-database.sh'));
    }

    protected function restoreScript(): string
    {
        return file_get_contents(base_path('ops/restore-database.sh'));
    }

    // --------------------------------------------------------------- berkas

    public function test_the_operational_artifacts_exist(): void
    {
        foreach ([
            'ops/backup-database.sh',
            'ops/restore-database.sh',
            'ops/smartsukses-worker.conf',
            'ops/smartsukses-cron',
        ] as $artifact) {
            $this->assertFileExists(base_path($artifact));
        }
    }

    // ------------------------------------------------------------ kredensial

    /**
     * Tidak ada kata sandi yang ter-commit, dan kata sandi tidak pernah menjadi
     * argumen baris perintah — argumen terlihat seluruh pengguna server lewat
     * `ps` (butir 371).
     */
    public function test_no_credential_is_committed_or_passed_on_the_command_line(): void
    {
        foreach ([$this->backupScript(), $this->restoreScript()] as $script) {
            // `-p<sandi>` dan `--password=<sandi>` keduanya terlihat di `ps`.
            //
            // Pencariannya menyasar bentuk yang sesungguhnya, bukan dua huruf
            // `-p` di mana pun: `find -print` mengandung potongan yang sama,
            // dan menjaring terlalu luas berarti test yang gagal karena hal
            // yang tidak diuji (butir 373).
            $this->assertDoesNotMatchRegularExpression('/--password=\S/', $script);
            $this->assertDoesNotMatchRegularExpression('/(mysql|mysqldump)[^
]*\s-p\S/', $script);

            // Kredensial dilewatkan lewat berkas opsi sementara.
            $this->assertStringContainsString('--defaults-extra-file=', $script);

            $this->assertStringNotContainsString('Password123', $script);
            $this->assertStringNotContainsString('root:', $script);
        }
    }

    public function test_the_option_file_is_private_and_always_removed(): void
    {
        foreach ([$this->backupScript(), $this->restoreScript()] as $script) {
            $this->assertStringContainsString('chmod 600', $script);
            // `trap ... EXIT` membuat berkas opsi terhapus juga ketika skripnya
            // gagal di tengah jalan.
            $this->assertMatchesRegularExpression('/trap\s+cleanup\s+EXIT/', $script);
        }
    }

    public function test_both_scripts_stop_on_the_first_error(): void
    {
        foreach ([$this->backupScript(), $this->restoreScript()] as $script) {
            $this->assertStringContainsString('set -euo pipefail', $script);
        }
    }

    // -------------------------------------------------------------- retensi

    /**
     * Blueprint 3.4: retensi 30 hari.
     */
    public function test_the_retention_window_is_thirty_days(): void
    {
        $this->assertStringContainsString('BACKUP_KEEP_DAYS:-30', $this->backupScript());
    }

    /**
     * Penghapusan otomatis adalah bagian paling berbahaya dari skrip mana pun.
     * Ia harus terbatas pada satu direktori, tanpa turun ke subdirektori, dan
     * hanya menyentuh berkas dengan nama yang dibuat skrip ini sendiri.
     */
    public function test_the_retention_delete_cannot_reach_beyond_the_backup_directory(): void
    {
        $script = $this->backupScript();

        $this->assertStringContainsString('-maxdepth 1', $script);
        $this->assertStringContainsString('-name "smartsukses-*.sql*"', $script);
        $this->assertStringContainsString('-type f', $script);

        // Tidak ada penghapusan rekursif dalam bentuk apa pun.
        $this->assertStringNotContainsString('rm -rf', $script);
        $this->assertStringNotContainsString('rm -r ', $script);
    }

    // ------------------------------------------------------- pagar pemulihan

    /**
     * Butir 372 — satu salah ketik tidak boleh dapat menghapus basis data
     * sungguhan.
     */
    public function test_the_restore_script_refuses_a_production_target_by_default(): void
    {
        $script = $this->restoreScript();

        // Hanya sasaran bernama _test/_testing/_restore/_drill yang lolos tanpa
        // persetujuan eksplisit.
        $this->assertStringContainsString('*_test|*_testing|*_restore|*_drill', $script);
        $this->assertStringContainsString('ALLOW_PRODUCTION_RESTORE', $script);

        // Dan persetujuan itu tidak boleh punya nilai bawaan yang menyala.
        $this->assertStringContainsString('${ALLOW_PRODUCTION_RESTORE:-}', $script);
        $this->assertStringNotContainsString('ALLOW_PRODUCTION_RESTORE=yes"', $script);
    }

    public function test_the_restore_script_never_uses_migrate_fresh(): void
    {
        $this->assertStringNotContainsString('migrate:fresh', $this->restoreScript());
        $this->assertStringNotContainsString('migrate:fresh', $this->backupScript());
    }

    // ---------------------------------------------------------- penjadwal

    public function test_the_scheduler_cron_runs_every_minute_and_does_not_duplicate_tasks(): void
    {
        $cron = file_get_contents(base_path('ops/smartsukses-cron'));

        $this->assertStringContainsString('* * * * * cd /var/www/smartsukses && php artisan schedule:run', $cron);

        // Tugas terjadwalnya sendiri milik routes/console.php. Cron hanya
        // memanggil penjadwalnya, dan tidak boleh menyebut satu tugas pun
        // secara langsung — itu akan menjadi jadwal kedua yang dapat berbeda.
        //
        // Yang diperiksa **baris perintahnya**, bukan komentarnya: komentar di
        // berkas itu memang menyebut tugas yang dijadwalkan, dan itu justru
        // berguna dibaca (butir 373).
        $this->assertStringNotContainsString('notifications:prune', $this->cronCommands());
    }

    /**
     * Baris perintah cron saja — komentar dan baris kosong dibuang.
     */
    protected function cronCommands(): string
    {
        return collect(file(base_path('ops/smartsukses-cron'), FILE_IGNORE_NEW_LINES))
            ->reject(fn (string $line) => str_starts_with(trim($line), '#') || trim($line) === '')
            ->implode('
');
    }

    public function test_the_backup_cron_runs_daily_and_keeps_a_log(): void
    {
        $cron = file_get_contents(base_path('ops/smartsukses-cron'));

        $this->assertStringContainsString('0 2 * * *', $cron);
        $this->assertStringContainsString('ops/backup-database.sh', $cron);
        // Backup yang berhenti bekerja tanpa log tidak terlihat sampai
        // dibutuhkan.
        $this->assertStringContainsString('backup.log', $cron);
    }

    public function test_the_cron_artifact_warns_about_the_server_timezone(): void
    {
        $cron = file_get_contents(base_path('ops/smartsukses-cron'));

        $this->assertStringContainsString('timedatectl', $cron);
    }

    /**
     * Tugas terjadwal aplikasi tetap satu, dan tetap milik routes/console.php.
     */
    public function test_the_application_schedule_still_holds_exactly_the_prune_task(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => $event->command)
            ->filter()
            ->values();

        $this->assertCount(1, $commands);
        $this->assertStringContainsString('notifications:prune', $commands->first());
    }

    // ------------------------------------------------------------- worker

    /**
     * Butir 368 — batas waktu worker harus lebih kecil daripada `retry_after`.
     * Bila terbalik, job yang masih berjalan diantrekan ulang dan dikerjakan
     * dua kali; pada penerbitan tagihan massal itu berarti tagihan ganda.
     */
    public function test_the_worker_timeout_stays_below_the_queue_retry_after(): void
    {
        $conf = file_get_contents(base_path('ops/smartsukses-worker.conf'));

        preg_match('/--timeout=(\d+)/', $conf, $matches);

        $timeout = (int) ($matches[1] ?? 0);
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(0, $timeout, 'Supervisor tidak menyebut --timeout.');
        $this->assertGreaterThan(
            $timeout,
            $retryAfter,
            "retry_after ({$retryAfter}) harus lebih besar daripada --timeout worker ({$timeout}).",
        );
    }

    /**
     * Percobaan ulang dan jeda adalah properti job, bukan argumen worker.
     * Menyebutnya di kedua tempat menciptakan dua sumber kebenaran.
     */
    public function test_retry_behaviour_belongs_to_the_jobs_not_the_worker(): void
    {
        $conf = file_get_contents(base_path('ops/smartsukses-worker.conf'));

        $this->assertStringNotContainsString('--tries=', $conf);
        $this->assertStringNotContainsString('--backoff=', $conf);

        $this->assertSame(3, (new GenerateReportCardPdf(1, 1))->tries);
        $this->assertSame(10, (new GenerateReportCardPdf(1, 1))->backoff);
        $this->assertSame(3, (new GenerateStudentFees(1, 1, '2026-01', '2026-01-10'))->tries);
    }

    public function test_the_worker_config_uses_the_database_queue(): void
    {
        $conf = file_get_contents(base_path('ops/smartsukses-worker.conf'));

        $this->assertStringContainsString('queue:work database', $conf);
        // Tidak ada Redis yang dikarang — Tech stack 3.1 memakai antrean
        // database.
        $this->assertStringNotContainsString('redis', strtolower($conf));

        $this->assertStringContainsString('autorestart=true', $conf);
        $this->assertStringContainsString('stopwaitsecs', $conf);
    }

    // ----------------------------------------------------- antrean lokal

    public function test_the_queue_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    public function test_the_database_queue_accepts_and_holds_a_job(): void
    {
        config(['queue.default' => 'database']);

        $this->assertSame(0, DB::table('jobs')->count());

        GenerateReportCardPdf::dispatch(1, 1);

        // Job benar-benar mendarat di tabel `jobs`, bukan dijalankan langsung.
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_the_production_template_keeps_the_database_queue(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('QUEUE_CONNECTION=database', $template);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $template);
    }
}
