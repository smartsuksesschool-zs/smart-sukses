<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Pemeriksaan kesiapan produksi.
 *
 * Dijalankan di server **sesudah** `.env` disusun dan `config:cache`
 * dijalankan, sebelum situsnya dibuka. Ia membaca konfigurasi yang sudah
 * benar-benar berlaku, bukan berkas contoh — sehingga ia menangkap justru
 * kekeliruan yang paling sering terjadi: `.env` yang tersalin dari lingkungan
 * lain (butir 361).
 *
 * **Tidak pernah mencetak nilai rahasia.** Yang dilaporkan hanya fakta ya/tidak
 * — apakah APP_KEY ada, bukan APP_KEY-nya; apakah kata sandi seeder disetel,
 * bukan kata sandinya. Perintah ini dijalankan di terminal server dan
 * keluarannya sering ikut tersalin ke catatan deployment (butir 362).
 *
 * Ia **bukan** pengganti checklist go-live. Ia hanya memeriksa konfigurasi
 * aplikasi; backup, cron, worker, TLS, dan uji beban berada di luar
 * jangkauannya dan tetap harus diverifikasi di server.
 */
class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Memeriksa konfigurasi aplikasi sebelum go-live (tidak mencetak nilai rahasia).';

    public function handle(): int
    {
        $results = $this->checks();

        $this->newLine();
        $this->line('  Pemeriksaan kesiapan produksi');
        $this->newLine();

        $this->table(
            ['', 'Pemeriksaan', 'Keterangan'],
            array_map(fn (array $check) => [
                $check['ok'] ? '<fg=green>OK</>' : '<fg=red>GAGAL</>',
                $check['label'],
                $check['ok'] ? '' : $check['hint'],
            ], $results),
        );

        $failed = array_values(array_filter($results, fn (array $check) => ! $check['ok']));

        if ($failed === []) {
            $this->info('  Seluruh pemeriksaan konfigurasi lolos.');
            $this->line('  Backup, cron penjadwal, worker antrean, TLS, dan uji beban');
            $this->line('  TIDAK diperiksa perintah ini — lihat docs/deployment-production.md.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error('  '.count($failed).' pemeriksaan gagal. Perbaiki sebelum situs dibuka.');
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    protected function checks(): array
    {
        $appUrl = (string) config('app.url');
        $origins = (array) config('cors.allowed_origins');

        return [
            $this->check(
                'APP_ENV adalah production',
                app()->environment('production'),
                'Setel APP_ENV=production.',
            ),
            $this->check(
                'APP_DEBUG mati',
                config('app.debug') === false,
                'Setel APP_DEBUG=false. Debug menyala membocorkan jejak galat dan isi konfigurasi.',
            ),
            $this->check(
                'APP_KEY terisi',
                filled(config('app.key')),
                'Jalankan php artisan key:generate.',
            ),
            $this->check(
                'APP_URL memakai https',
                str_starts_with(strtolower($appUrl), 'https://'),
                'Setel APP_URL ke alamat https produksi.',
            ),
            $this->check(
                'Cookie sesi bertanda Secure',
                config('session.secure') === true,
                'Setel SESSION_SECURE_COOKIE=true.',
            ),
            $this->check(
                'Cookie sesi HttpOnly',
                config('session.http_only') === true,
                'Setel SESSION_HTTP_ONLY=true.',
            ),
            $this->check(
                'CORS tidak mengizinkan seluruh origin',
                $origins !== [] && ! in_array('*', $origins, true),
                'Setel CORS_ALLOWED_ORIGINS ke domain produksi saja.',
            ),
            $this->check(
                'Proxy tepercaya dikonfigurasi',
                filled(config('trustedproxy.proxies')),
                'Setel TRUSTED_PROXIES. Tanpa itu audit_logs.ip_address berisi alamat proxy.',
            ),
            $this->check(
                'Antrean tidak berjalan sinkron',
                config('queue.default') !== 'sync',
                'Setel QUEUE_CONNECTION=database dan jalankan worker.',
            ),
            $this->check(
                'Pengirim surel bukan log',
                config('mail.default') !== 'log',
                'Setel MAIL_MAILER ke transport sungguhan; tautan lupa kata sandi tidak akan terkirim.',
            ),
            $this->check(
                'Skrip backup tersedia',
                is_file(base_path('ops/backup-database.sh')),
                'Berkas ops/backup-database.sh hilang; backup harian tidak dapat berjalan.',
            ),
            $this->check(
                'Batas waktu worker lebih kecil daripada retry_after',
                (int) config('queue.connections.database.retry_after') > 60,
                'retry_after antrean harus lebih besar daripada --timeout worker (60), '
                    .'kalau tidak job yang masih berjalan dikerjakan dua kali.',
            ),
            $this->check(
                'Kata sandi seeder disetel',
                filled(env('SEED_ADMIN_PASSWORD')),
                'Setel SEED_ADMIN_PASSWORD sebelum menjalankan db:seed.',
            ),
        ];
    }

    /**
     * @return array{label: string, ok: bool, hint: string}
     */
    protected function check(string $label, bool $ok, string $hint): array
    {
        return ['label' => $label, 'ok' => $ok, 'hint' => $hint];
    }
}
