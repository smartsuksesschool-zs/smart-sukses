<?php

namespace Tests\Feature\Ops;

use App\Models\Exam;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SiteBlock;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\EnvironmentBanner;
use App\Support\SeedPassword;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\SimulationSeeder;
use Database\Seeders\Sprint4DemoSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Kesiapan staging/UAT — staging.smartsukses.sch.id.
 *
 * Staging punya nama host sungguhan dan dapat dibuka siapa pun yang tahu
 * alamatnya. Yang diuji di sini adalah dua akibatnya: kata sandi bawaan
 * repository tidak boleh lahir di sana (butir 509), dan staf harus dapat
 * membedakannya dari produksi tanpa membaca bilah URL (butir 510).
 */
class StagingReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');

        parent::tearDown();
    }

    protected function asEnvironment(string $env): void
    {
        app()->detectEnvironment(fn (): string => $env);
    }

    // ================================================== kata sandi seeding

    public function test_kata_sandi_bawaan_hanya_boleh_di_lokal_dan_uji(): void
    {
        $this->assertSame(['local', 'testing'], SeedPassword::optionalEnvironments());

        foreach (SeedPassword::optionalEnvironments() as $env) {
            $this->asEnvironment($env);

            $this->assertSame(SeedPassword::FALLBACK, SeedPassword::resolve());
        }
    }

    /**
     * Inti temuan keamanan: pagar lama hanya menyebut `production`, sehingga
     * `APP_ENV=staging` melewatinya dan seluruh akun awal lahir dengan kata
     * sandi yang tercetak di repository — di alamat yang dapat dibuka siapa pun.
     */
    public function test_lingkungan_ber_hostname_menolak_kata_sandi_bawaan(): void
    {
        foreach (['staging', 'uat', 'demo', 'production'] as $env) {
            $this->asEnvironment($env);

            try {
                SeedPassword::resolve();
                $this->fail("lingkungan {$env} seharusnya menolak kata sandi bawaan");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(SeedPassword::ENV_KEY, $e->getMessage());
                $this->assertStringContainsString($env, $e->getMessage());

                // Kata sandinya sendiri tidak pernah ikut dicetak.
                $this->assertStringNotContainsString(SeedPassword::FALLBACK, $e->getMessage());
            }
        }
    }

    public function test_lingkungan_yang_belum_dikenal_ikut_terpagari(): void
    {
        $this->asEnvironment('sandbox');

        $this->expectException(RuntimeException::class);

        SeedPassword::resolve();
    }

    /**
     * Resolver membaca **config**, bukan `env()`.
     *
     * `env()` di luar berkas config mengembalikan NULL begitu `config:cache`
     * dijalankan — yaitu tepat di staging dan produksi. Pagar yang membacanya
     * langsung akan menolak seeding di server yang justru sudah benar
     * konfigurasinya (butir 357, butir 511).
     */
    public function test_kata_sandi_dibaca_dari_config(): void
    {
        $this->asEnvironment('staging');

        config([SeedPassword::CONFIG_KEY => 'KataSandiStagingKarangan']);

        $this->assertTrue(SeedPassword::isConfigured());
        $this->assertSame('KataSandiStagingKarangan', SeedPassword::resolve());
    }

    /**
     * Bukti bahwa jalurnya benar-benar config: env diisi, config dikosongkan,
     * dan resolver tetap menolak. Inilah keadaan sesudah `config:cache`
     * dibangun tanpa kata sandi.
     */
    public function test_env_tanpa_config_tidak_menembus_pagar(): void
    {
        $this->asEnvironment('staging');

        putenv(SeedPassword::ENV_KEY.'=TidakLewatConfig');
        config([SeedPassword::CONFIG_KEY => null]);

        try {
            $this->assertFalse(SeedPassword::isConfigured());
            $this->expectException(RuntimeException::class);

            SeedPassword::resolve();
        } finally {
            putenv(SeedPassword::ENV_KEY);
        }
    }

    public function test_berkas_config_membaca_variabel_env_yang_benar(): void
    {
        $contents = File::get(config_path('seeding.php'));

        $this->assertStringContainsString("env('".SeedPassword::ENV_KEY."')", $contents);
        // Berkas config tidak boleh memuat kata sandinya sendiri.
        $this->assertStringNotContainsString(SeedPassword::FALLBACK, $contents);
    }

    public function test_daftar_lingkungan_longgar_datang_dari_config(): void
    {
        $this->assertSame(['local', 'testing'], config(SeedPassword::ENVIRONMENTS_KEY));
        $this->assertSame(['local', 'testing'], SeedPassword::optionalEnvironments());
    }

    public function test_spasi_saja_tidak_dianggap_kata_sandi(): void
    {
        $this->asEnvironment('staging');

        config([SeedPassword::CONFIG_KEY => '   ']);

        $this->assertFalse(SeedPassword::isConfigured());

        $this->expectException(RuntimeException::class);

        SeedPassword::resolve();
    }

    /**
     * `app:production-check` dijalankan **sesudah** `config:cache`. Pemeriksaan
     * lama memakai `env()` dan karena itu melaporkan "belum disetel" pada
     * server yang sudah benar (butir 511).
     */
    public function test_pemeriksaan_produksi_membaca_config_bukan_env(): void
    {
        $contents = File::get(app_path('Console/Commands/ProductionCheck.php'));

        $this->assertStringNotContainsString("env('".SeedPassword::ENV_KEY."')", $contents);
        $this->assertStringContainsString('SeedPassword::isConfigured()', $contents);
    }

    public function test_seeder_akun_berhenti_di_staging_tanpa_kata_sandi(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        $this->asEnvironment('staging');

        $this->expectException(RuntimeException::class);

        app(UserSeeder::class)->run();
    }

    /**
     * Penolakan terjadi **sebelum** satu baris pun ditulis.
     *
     * Sebelumnya kata sandi diselesaikan di tengah jalan, saat akun pertama
     * hendak dibuat, sehingga seeder berhenti setelah sebagian tabel terisi —
     * meninggalkan jadwal dan ujian tanpa akun yang memilikinya (butir 512).
     */
    #[DataProvider('akunSeeders')]
    public function test_penolakan_tidak_meninggalkan_satu_baris_pun(string $seeder): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        $before = $this->rowCounts();

        $this->asEnvironment('staging');
        config([SeedPassword::CONFIG_KEY => null]);

        try {
            app($seeder)->run();
            $this->fail("{$seeder} seharusnya menolak tanpa kata sandi");
        } catch (RuntimeException) {
            // yang diperiksa akibatnya, bukan pesannya
        }

        $this->assertSame(
            $before,
            $this->rowCounts(),
            "{$seeder} menulis baris walaupun kata sandinya ditolak",
        );
    }

    /**
     * Tabel yang seharusnya tetap kosong ketika seeding ditolak.
     *
     * Keuangan ikut dihitung sejak SimulationSeeder membawa bekal tagihan untuk
     * UAT (butir 527): jaminannya "tidak satu baris pun", dan jaminan itu tidak
     * boleh diam-diam menyempit menjadi "tidak satu baris pun di tabel yang
     * kebetulan disebut waktu test ini ditulis".
     *
     * @return array<string, int>
     */
    protected function rowCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'students' => Student::query()->count(),
            'schedules' => Schedule::query()->count(),
            'exams' => Exam::query()->count(),
            'student_fees' => StudentFee::query()->count(),
            'payments' => Payment::query()->count(),
            'transactions' => Transaction::query()->count(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function akunSeeders(): array
    {
        return [
            'UserSeeder' => [UserSeeder::class],
            'Sprint4DemoSeeder' => [Sprint4DemoSeeder::class],
            'SimulationSeeder' => [SimulationSeeder::class],
        ];
    }

    /**
     * Jalur sintetis yang memang disetujui untuk UAT tetap berjalan begitu
     * kata sandinya disetel.
     */
    public function test_kata_sandi_yang_disetel_membuka_jalur_seed_sintetis(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        $this->asEnvironment('staging');
        config([SeedPassword::CONFIG_KEY => 'KataSandiStagingKarangan']);

        app(UserSeeder::class)->run();

        $this->assertGreaterThan(0, User::query()->count());

        // Akun tetap wajib ganti kata sandi pada login pertama.
        $this->assertSame(0, User::query()->where('must_change_password', false)->count());
    }

    /**
     * Tidak ada satu pun kata sandi bawaan yang tersisa tersebar di seeder.
     * Kalau ada, pagar di SeedPassword dapat dilewati begitu saja.
     */
    public function test_tidak_ada_kata_sandi_bawaan_yang_tercecer_di_seeder(): void
    {
        foreach (File::allFiles(database_path('seeders')) as $file) {
            $this->assertStringNotContainsString(
                SeedPassword::FALLBACK,
                $file->getContents(),
                $file->getFilename().' memuat kata sandi bawaan sendiri',
            );
        }
    }

    // ================================================== pagar produksi simulasi

    /**
     * Pagar produksi SimulationSeeder tidak boleh melemah karena pekerjaan
     * staging. Ia membuat akun yang dapat login.
     */
    public function test_simulation_seeder_tetap_menolak_produksi(): void
    {
        $this->asEnvironment('production');

        $this->expectException(RuntimeException::class);

        // Dipanggil langsung, bukan lewat `$this->seed()`: perintah `db:seed`
        // meminta konfirmasi di produksi sebelum seeder-nya sempat berjalan,
        // sehingga yang teruji akan menjadi pagar Laravel, bukan pagar seeder.
        app(SimulationSeeder::class)->run();
    }

    // ================================================== penanda lingkungan

    public function test_penanda_muncul_di_lingkungan_non_produksi_ber_hostname(): void
    {
        foreach (['staging', 'uat', 'demo'] as $env) {
            $this->asEnvironment($env);

            $this->assertTrue(EnvironmentBanner::shouldRender(), "penanda hilang di {$env}");
        }
    }

    public function test_penanda_tidak_pernah_ada_di_produksi(): void
    {
        $this->asEnvironment('production');

        $this->assertFalse(EnvironmentBanner::shouldRender());

        // Tidak sekadar disembunyikan CSS: markupnya tidak dirender sama sekali.
        $html = view('components.env-banner')->render();

        $this->assertSame('', trim($html));
    }

    public function test_penanda_tidak_muncul_di_lokal(): void
    {
        $this->asEnvironment('local');

        $this->assertFalse(EnvironmentBanner::shouldRender());
    }

    public function test_penanda_staging_terbaca_jelas(): void
    {
        $this->asEnvironment('staging');

        $this->assertSame('STAGING / UAT', EnvironmentBanner::label());

        $html = view('components.env-banner')->render();

        $this->assertStringContainsString('STAGING / UAT', $html);
        // Tidak boleh menghalangi tombol apa pun di bawahnya.
        $this->assertStringContainsString('pointer-events: none', $html);
        $this->assertStringContainsString('position: fixed', $html);
    }

    public function test_penanda_tidak_membocorkan_apa_pun_tentang_server(): void
    {
        $this->asEnvironment('staging');

        $html = view('components.env-banner')->render();

        foreach ([config('app.key'), config('database.connections.mysql.password')] as $secret) {
            if (blank($secret)) {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $html);
        }

        foreach (['APP_KEY', 'DB_PASSWORD', 'database', 'php', 'version'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $html);
        }
    }

    /**
     * Penanda dipasang di seluruh permukaan yang dilihat manusia. Satu halaman
     * yang terlewat adalah halaman tempat kekeliruan itu tetap mungkin terjadi.
     */
    public function test_penanda_terpasang_di_seluruh_permukaan(): void
    {
        foreach (['landing', 'portal', 'ppdb'] as $layout) {
            $this->assertStringContainsString(
                '<x-env-banner />',
                File::get(resource_path("views/layouts/{$layout}.blade.php")),
                "layout {$layout} tanpa penanda lingkungan",
            );
        }

        // Panel admin memakai render hook, bukan layout Blade.
        $panel = File::get(app_path('Providers/Filament/AdminPanelProvider.php'));

        $this->assertStringContainsString('BODY_END', $panel);
        $this->assertStringContainsString('components.env-banner', $panel);
    }

    // ================================================== halaman publik

    public function test_halaman_muka_tidak_membawa_penanda_di_produksi(): void
    {
        $this->asEnvironment('production');

        $this->get('/')->assertOk()->assertDontSee('STAGING', false);
    }

    public function test_login_tetap_satu_pintu_dan_tidak_berubah(): void
    {
        $this->get('/login')->assertOk();

        foreach (['/login/siswa', '/login/guru', '/login/admin'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }

    // ================================================== kontrak env staging

    public function test_penanda_tidak_muncul_di_lingkungan_uji(): void
    {
        $this->asEnvironment('testing');

        $this->assertFalse(EnvironmentBanner::shouldRender());
        $this->assertSame('', trim(view('components.env-banner')->render()));
    }

    /**
     * Lingkungan yang tidak dikenal tidak boleh ikut berlabel STAGING. Label
     * yang salah sama menyesatkannya dengan tidak ada label.
     */
    public function test_lingkungan_tak_dikenal_tidak_berlabel_staging(): void
    {
        $this->asEnvironment('sandbox');

        $this->assertFalse(EnvironmentBanner::shouldRender());
        $this->assertSame('', trim(view('components.env-banner')->render()));
    }

    // ================================================== seeder otomatis

    public function test_databaseseeder_hanya_memanggil_prasyarat_struktural(): void
    {
        $contents = File::get(database_path('seeders/DatabaseSeeder.php'));

        preg_match('/\$this->call\(\[(.*?)\]\);/s', $contents, $m);

        $called = array_values(array_filter(array_map(
            fn (string $line): string => trim(str_replace(['::class', ','], '', $line)),
            explode("\n", $m[1] ?? ''),
        )));

        $this->assertSame(
            ['RolePermissionSeeder', 'SchoolSeeder', 'UserSeeder'],
            $called,
            'daftar seeder otomatis berubah — setiap tambahan harus punya alasan tertulis',
        );
    }

    public function test_seeder_demo_dan_isi_publik_tetap_dipanggil_dengan_sengaja(): void
    {
        $contents = File::get(database_path('seeders/DatabaseSeeder.php'));

        preg_match('/\$this->call\(\[(.*?)\]\);/s', $contents, $m);
        $called = $m[1] ?? '';

        foreach (['SimulationSeeder', 'Sprint4DemoSeeder', 'PublicSiteSeeder'] as $seeder) {
            $this->assertStringNotContainsString($seeder, $called, "{$seeder} tidak boleh otomatis");
        }
    }

    public function test_isi_publik_tidak_terbit_lewat_db_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        // `php artisan db:seed` di staging tidak boleh menerbitkan apa pun ke
        // halaman muka (butir 481).
        $this->assertSame(0, SiteBlock::query()->count());
    }

    // ================================================== kesehatan & sesi

    public function test_endpoint_kesehatan_ada_dan_tidak_membocorkan_apa_pun(): void
    {
        $response = $this->get('/up');

        $response->assertOk();

        $body = $response->getContent();

        foreach ([
            config('database.connections.mysql.database'),
            config('app.key'),
            PHP_VERSION,
            base_path(),
        ] as $secret) {
            if (blank($secret)) {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $body);
        }

        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_DATABASE', 'phpinfo'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }

    public function test_tidak_ada_endpoint_kesehatan_kedua(): void
    {
        $health = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => preg_match('~^(up|health|healthz|status)$~', $uri) === 1)
            ->values();

        $this->assertSame(['up'], $health->all());
    }

    /**
     * Cookie sesi staging harus terikat pada hostnya sendiri. Cookie untuk
     * `.smartsukses.sch.id` akan ikut terkirim ke produksi dan ke blog.
     */
    public function test_cookie_sesi_staging_terikat_pada_hostnya(): void
    {
        $contents = File::get(base_path('.env.staging.example'));

        $this->assertMatchesRegularExpression('/^SESSION_DOMAIN=null$/m', $contents);
        $this->assertStringNotContainsString('.smartsukses.sch.id', str_replace(
            ['staging.smartsukses.sch.id', 'no-reply@staging.smartsukses.sch.id'],
            '',
            $contents,
        ));

        // Laravel mengubah string "null" menjadi NULL yang sesungguhnya,
        // sehingga cookienya host-only.
        config(['session.domain' => null]);
        $this->assertNull(config('session.domain'));
    }

    public function test_contoh_env_staging_tidak_memuat_rahasia(): void
    {
        $path = base_path('.env.staging.example');

        $this->assertFileExists($path);

        $contents = File::get($path);

        // Setiap kunci rahasia harus kosong.
        foreach (['APP_KEY', 'DB_PASSWORD', 'SEED_ADMIN_PASSWORD'] as $key) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=\s*$/m',
                $contents,
                "{$key} harus kosong di berkas contoh",
            );
        }

        $this->assertStringNotContainsString(SeedPassword::FALLBACK, $contents);
    }

    public function test_contoh_env_staging_memakai_pagar_yang_sama_dengan_produksi(): void
    {
        $contents = File::get(base_path('.env.staging.example'));

        $this->assertStringContainsString('APP_ENV=staging', $contents);
        $this->assertStringContainsString('APP_DEBUG=false', $contents);
        $this->assertStringContainsString('APP_URL=https://staging.smartsukses.sch.id', $contents);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $contents);

        // Surel sengaja ditahan supaya penguji tidak menyurati siapa pun.
        $this->assertStringContainsString('MAIL_MAILER=log', $contents);

        // Basis data staging tidak boleh menunjuk basis data produksi.
        $this->assertStringNotContainsString('DB_DATABASE=smartsukses'."\n", $contents);
        $this->assertStringContainsString('DB_DATABASE=smartsukses_staging', $contents);

        $this->assertStringNotContainsString('CORS_ALLOWED_ORIGINS=*', $contents);
    }
}
