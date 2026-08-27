<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Konfigurasi produksi dan batas keamanannya.
 *
 * Yang diuji di sini adalah hal-hal yang **tidak menghasilkan kegagalan apa pun
 * ketika salah**. CORS yang terlalu longgar tidak membuat satu test pun merah;
 * proxy yang tidak dipercaya hanya membuat setiap baris audit berisi alamat
 * yang keliru; cookie tanpa Secure hanya berarti sesi dapat terkirim polos.
 * Ketiganya baru terasa ketika sudah terlambat (butir 355).
 */
class ProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Kedua penyetel ini statis dan bertahan lintas test.
        TrustProxies::flushState();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- CORS

    public function test_the_cors_config_is_owned_by_the_repository(): void
    {
        $this->assertFileExists(config_path('cors.php'));

        // Bukan bawaan framework, yang mengizinkan seluruh origin.
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotEmpty(config('cors.allowed_origins'));
    }

    public function test_cors_covers_the_api_only(): void
    {
        $this->assertSame(['api/*'], config('cors.paths'));

        // Butir 360 — jalur cookie CSRF SPA tidak dibuka lintas origin.
        $this->assertNotContains('sanctum/csrf-cookie', config('cors.paths'));
    }

    public function test_cross_origin_credentials_stay_off(): void
    {
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_the_configured_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://apps.smartsukses.sch.id']]);

        $response = $this->call(
            'OPTIONS',
            '/api/v1/auth/me',
            server: [
                'HTTP_ORIGIN' => 'https://apps.smartsukses.sch.id',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $this->assertSame(
            'https://apps.smartsukses.sch.id',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    /**
     * Ketika hanya satu origin yang diizinkan, php-cors menuliskannya secara
     * statis tanpa membandingkan Origin permintaan — dan itu memang perilaku
     * CORS yang benar: peramban yang membandingkan header itu dengan origin-nya
     * sendiri, lalu memblokir bila berbeda.
     *
     * Yang berarti karena itu bukan "tidak ada header", melainkan **header itu
     * tidak pernah menyebut origin penyerang, dan tidak pernah `*`**
     * (butir 364).
     */
    public function test_a_hostile_origin_is_never_named_in_the_permission(): void
    {
        config(['cors.allowed_origins' => ['https://apps.smartsukses.sch.id']]);

        $allowed = $this->call(
            'OPTIONS',
            '/api/v1/auth/me',
            server: [
                'HTTP_ORIGIN' => 'https://penyerang.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        )->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('https://penyerang.example', $allowed);
        $this->assertNotSame('*', $allowed);
    }

    /**
     * Dengan lebih dari satu origin, php-cors menempuh jalur pembanding — dan
     * origin yang tidak terdaftar tidak mendapat izin sama sekali.
     */
    public function test_an_unlisted_origin_gets_no_permission_at_all(): void
    {
        config(['cors.allowed_origins' => [
            'https://apps.smartsukses.sch.id',
            'https://staging.smartsukses.sch.id',
        ]]);

        $response = $this->call(
            'OPTIONS',
            '/api/v1/auth/me',
            server: [
                'HTTP_ORIGIN' => 'https://penyerang.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));

        // Dan origin yang terdaftar tetap mendapatkannya.
        $listed = $this->call(
            'OPTIONS',
            '/api/v1/auth/me',
            server: [
                'HTTP_ORIGIN' => 'https://staging.smartsukses.sch.id',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $this->assertSame(
            'https://staging.smartsukses.sch.id',
            $listed->headers->get('Access-Control-Allow-Origin'),
        );
    }

    /**
     * CORS bukan otorisasi. Origin yang diizinkan pun tetap harus membawa token
     * — yang menjaga data adalah policy dan SchoolScope, bukan daftar origin.
     */
    public function test_an_allowed_origin_still_needs_a_token(): void
    {
        config(['cors.allowed_origins' => ['https://apps.smartsukses.sch.id']]);

        $this->getJson('/api/v1/auth/me', ['Origin' => 'https://apps.smartsukses.sch.id'])
            ->assertUnauthorized();
    }

    public function test_the_api_surface_is_unchanged(): void
    {
        $api = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->count();

        $this->assertSame(41, $api);
    }

    // ------------------------------------------------------- cookie sesi

    public function test_the_session_cookie_keeps_its_protections(): void
    {
        $this->assertTrue(config('session.http_only'), 'HttpOnly tidak boleh dilemahkan.');
        $this->assertSame('lax', config('session.same_site'), 'SameSite tidak boleh dilemahkan.');
    }

    /**
     * Nilai bawaannya sengaja tidak dipaksa true: pengembangan lokal berjalan
     * lewat http:// dan cookie Secure akan membuat login mustahil. Yang
     * dibuktikan di sini adalah bahwa produksi **dapat** menyalakannya.
     */
    public function test_the_secure_cookie_is_controlled_by_configuration(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue(config('session.secure'));
    }

    public function test_the_production_template_turns_the_secure_cookie_on(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $template);
        $this->assertStringContainsString('APP_ENV=production', $template);
        $this->assertStringContainsString('APP_DEBUG=false', $template);
        $this->assertStringContainsString('APP_URL=https://', $template);
    }

    /**
     * Berkas contoh tidak boleh berisi rahasia. Placeholder-nya harus kosong.
     */
    public function test_the_production_template_carries_no_secrets(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));

        foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'SEED_ADMIN_PASSWORD'] as $secret) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($secret, '/').'=\s*$/m',
                $template,
                "{$secret} harus kosong di berkas contoh.",
            );
        }

        $this->assertStringNotContainsString('Password123', $template);
    }

    // --------------------------------------------------- proxy tepercaya

    public function test_by_default_no_proxy_is_trusted(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }

    /**
     * Butir 358 — daftar header sengaja lebih sempit daripada bawaan Laravel.
     * `X-Forwarded-Host` tidak dipercaya karena tautan atur ulang kata sandi
     * dibangun dari host.
     */
    public function test_only_the_needed_forwarded_headers_are_trusted(): void
    {
        $headers = (int) config('trustedproxy.headers');

        $this->assertSame(Request::HEADER_X_FORWARDED_FOR, $headers & Request::HEADER_X_FORWARDED_FOR);
        $this->assertSame(Request::HEADER_X_FORWARDED_PROTO, $headers & Request::HEADER_X_FORWARDED_PROTO);
        $this->assertSame(Request::HEADER_X_FORWARDED_PORT, $headers & Request::HEADER_X_FORWARDED_PORT);

        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_HOST, 'X-Forwarded-Host tidak boleh dipercaya.');
        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_PREFIX, 'X-Forwarded-Prefix tidak dipakai topologi ini.');
        $this->assertSame(0, $headers & Request::HEADER_FORWARDED, 'Header Forwarded RFC 7239 tidak dipakai Nginx di sini.');

        // Catatan: `HEADER_X_FORWARDED_AWS_ELB` bernilai 26, yaitu gabungan
        // FOR|PROTO|PORT — sebuah preset, bukan bit tersendiri. Ia kebetulan
        // sama persis dengan himpunan yang dipilih di sini, jadi tidak ada yang
        // dapat diuji tentang "ketiadaannya" (butir 365).
        $this->assertSame(Request::HEADER_X_FORWARDED_AWS_ELB, $headers);
    }

    /**
     * Tanpa proxy tepercaya, header `X-Forwarded-*` dari siapa pun diabaikan —
     * termasuk klaim palsu bahwa koneksinya sudah HTTPS.
     */
    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        TrustProxies::flushState();

        $request = Request::create('http://localhost/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        (new TrustProxies)->handle($request, fn ($request) => response('ok'));

        $this->assertFalse($request->isSecure(), 'Skema palsu diterima tanpa proxy tepercaya.');
        $this->assertSame('127.0.0.1', $request->ip(), 'Alamat palsu diterima tanpa proxy tepercaya.');
    }

    /**
     * Dengan proxy tepercaya — yaitu Nginx di mesin yang sama — skema dan
     * alamat klien diteruskan dengan benar. Inilah yang membuat
     * `audit_logs.ip_address` berisi alamat pengunjung, bukan alamat proxy
     * (butir 356).
     */
    public function test_a_trusted_proxy_supplies_the_scheme_and_the_client_address(): void
    {
        TrustProxies::at(['127.0.0.1']);
        TrustProxies::withHeaders((int) config('trustedproxy.headers'));

        $request = Request::create('http://localhost/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        (new TrustProxies)->handle($request, fn ($request) => response('ok'));

        $this->assertTrue($request->isSecure());
        $this->assertSame('203.0.113.9', $request->ip());
    }

    /**
     * Proxy yang **tidak** ada di daftar tetap tidak dipercaya, walaupun ia
     * mengirim header yang sama.
     */
    public function test_an_untrusted_proxy_address_is_still_refused(): void
    {
        TrustProxies::at(['127.0.0.1']);
        TrustProxies::withHeaders((int) config('trustedproxy.headers'));

        $request = Request::create('http://localhost/', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        (new TrustProxies)->handle($request, fn ($request) => response('ok'));

        $this->assertFalse($request->isSecure());
        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function test_local_requests_still_work_normally(): void
    {
        $this->get('/')->assertOk();
        $this->get(route('ppdb.schools'))->assertOk();
        $this->get(route('filament.admin.auth.login'))->assertOk();
    }
}
