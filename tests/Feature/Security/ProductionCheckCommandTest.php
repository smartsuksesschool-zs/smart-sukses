<?php

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SchoolSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `app:production-check` dan pagar seeding produksi.
 *
 * Keduanya menjaga hal yang sama: konfigurasi yang tidak siap tidak boleh lolos
 * diam-diam. Perintahnya berhenti dengan kode keluar bukan nol supaya dapat
 * dipasang di skrip deployment; seedernya melempar galat supaya tidak terlewat
 * di tengah keluaran (butir 361, 363).
 */
class ProductionCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_fails_on_a_development_configuration(): void
    {
        $this->artisan('app:production-check')->assertExitCode(1);
    }

    public function test_the_command_names_what_is_wrong(): void
    {
        $this->artisan('app:production-check')
            ->expectsOutputToContain('APP_ENV')
            ->assertExitCode(1);
    }

    public function test_the_command_passes_on_a_production_shaped_configuration(): void
    {
        $this->withProductionConfig();

        $this->artisan('app:production-check')->assertExitCode(0);
    }

    /**
     * Setiap pemeriksaan harus benar-benar menjaga sesuatu: mematikan satu
     * saja harus menjatuhkan perintahnya.
     */
    public function test_each_guard_actually_guards(): void
    {
        foreach ([
            'app.debug' => true,
            'app.url' => 'http://apps.smartsukses.sch.id',
            'session.secure' => false,
            'session.http_only' => false,
            'queue.default' => 'sync',
            'mail.default' => 'log',
        ] as $key => $broken) {
            $this->withProductionConfig();

            config([$key => $broken]);

            $this->artisan('app:production-check')
                ->assertExitCode(1);
        }

        // Daftar origin yang mengizinkan seluruh dunia juga harus menjatuhkannya.
        $this->withProductionConfig();
        config(['cors.allowed_origins' => ['*']]);
        $this->artisan('app:production-check')->assertExitCode(1);

        // Begitu pula tanpa proxy tepercaya.
        $this->withProductionConfig();
        config(['trustedproxy.proxies' => null]);
        $this->artisan('app:production-check')->assertExitCode(1);
    }

    /**
     * Butir 362 — keluaran perintah ini sering ikut tersalin ke catatan
     * deployment. Ia tidak boleh membawa satu pun nilai rahasia.
     */
    public function test_the_command_never_prints_a_secret(): void
    {
        $this->withProductionConfig();

        config(['app.key' => 'base64:RAHASIA-KUNCI-APLIKASI-JANGAN-TERCETAK=']);
        putenv('SEED_ADMIN_PASSWORD=RAHASIA-KATA-SANDI-SEEDER');

        $this->artisan('app:production-check')
            ->doesntExpectOutputToContain('RAHASIA-KUNCI-APLIKASI-JANGAN-TERCETAK')
            ->doesntExpectOutputToContain('RAHASIA-KATA-SANDI-SEEDER')
            ->assertExitCode(0);

        putenv('SEED_ADMIN_PASSWORD');
    }

    // ------------------------------------------------- pagar seeding produksi

    public function test_seeding_in_production_without_a_password_is_refused(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        putenv('SEED_ADMIN_PASSWORD');
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SEED_ADMIN_PASSWORD');

            (new UserSeeder)->run();
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    /**
     * Pesannya tidak boleh menyebut kata sandi bawaannya.
     */
    public function test_the_refusal_does_not_reveal_the_fallback(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);

        putenv('SEED_ADMIN_PASSWORD');
        app()->detectEnvironment(fn () => 'production');

        try {
            (new UserSeeder)->run();
            $this->fail('Seeding produksi tanpa kata sandi seharusnya ditolak.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('Password123', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    /**
     * Alur pengembang lokal tidak boleh terganggu: seeding di luar produksi
     * tetap berjalan dengan nilai cadangan.
     */
    public function test_local_seeding_still_works_without_the_variable(): void
    {
        putenv('SEED_ADMIN_PASSWORD');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SchoolSeeder::class);
        $this->seed(UserSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'superadmin@smartsukses.sch.id',
            // Kata sandi sementara tetap wajib diganti pada login pertama.
            'must_change_password' => true,
        ]);
    }

    /**
     * Sprint4DemoSeeder tetap tidak terdaftar — `db:seed` di produksi tidak
     * boleh membuat akun demo.
     */
    public function test_the_demo_seeder_stays_unregistered(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('Sprint4DemoSeeder', $source);
        $this->assertStringContainsString('UserSeeder', $source);
    }

    protected function withProductionConfig(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://apps.smartsukses.sch.id',
            'session.secure' => true,
            'session.http_only' => true,
            'cors.allowed_origins' => ['https://apps.smartsukses.sch.id'],
            'trustedproxy.proxies' => ['127.0.0.1'],
            'queue.default' => 'database',
            'mail.default' => 'smtp',
        ]);

        putenv('SEED_ADMIN_PASSWORD=cukup-panjang-untuk-produksi');

        app()->detectEnvironment(fn () => 'production');
    }

    protected function tearDown(): void
    {
        putenv('SEED_ADMIN_PASSWORD');
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }
}
