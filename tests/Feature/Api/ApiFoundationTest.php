<?php

namespace Tests\Feature\Api;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * API 4.1 — konvensi, dan 4.2 — autentikasi.
 */
class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $bendahara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
        $this->bendahara = User::factory()->forSchool($this->school)
            ->withRole(RoleName::Bendahara)
            ->create(['email' => 'bendahara@sekolah.test', 'password' => 'RahasiaKuat1']);
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Melupakan guard yang sudah teresolusi.
     *
     * Di produksi setiap request adalah proses baru, sehingga penggunanya
     * selalu diresolusi ulang dari token. Di dalam satu test, container-nya
     * dipakai bersama dan AuthManager menyimpan pengguna dari request
     * sebelumnya — tanpa ini, request kedua akan memakai keadaan lama dan
     * testnya justru berhenti menguji apa yang dimaksud.
     */
    protected function forgetResolvedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ------------------------------------------------------------ fondasi

    public function test_the_api_route_file_is_registered(): void
    {
        $this->assertFileExists(base_path('routes/api.php'));

        $paths = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('api/v1/auth/login', $paths);
    }

    /**
     * API 4.1 — Base URL `.../api/v1`.
     */
    public function test_every_api_route_sits_under_the_versioned_prefix(): void
    {
        $apiRoutes = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'api/'));

        $this->assertGreaterThan(0, $apiRoutes->count());

        foreach ($apiRoutes as $uri) {
            $this->assertStringStartsWith('api/v1/', $uri);
        }
    }

    public function test_the_sanctum_guard_is_configured(): void
    {
        $this->assertSame('sanctum', config('auth.guards.api.driver'));
        $this->assertSame('users', config('auth.guards.api.provider'));
        // Panel tetap memakai sesi.
        $this->assertSame('session', config('auth.guards.web.driver'));
    }

    public function test_an_unauthenticated_request_returns_a_json_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token tidak valid atau sudah tidak berlaku.',
                'errors' => null,
            ]);
    }

    // --------------------------------------------------------------- login

    public function test_login_returns_a_bearer_token_user_and_school(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'RahasiaKuat1',
        ])->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.email', 'bendahara@sekolah.test');
        $response->assertJsonPath('data.user.role', RoleName::Bendahara->value);
        $response->assertJsonPath('data.user.school.id', $this->school->id);
        $response->assertJsonPath('data.user.school.primary_color', $this->school->primary_color);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_never_leaks_password_fields(): void
    {
        $payload = $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'RahasiaKuat1',
        ])->json('data.user');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
    }

    public function test_login_records_the_last_login_without_writing_a_fake_audit_row(): void
    {
        $before = AuditLog::query()->count();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'RahasiaKuat1',
        ])->assertOk();

        $this->assertNotNull($this->bendahara->fresh()->last_login_at);
        // `last_login_at` ditulis saveQuietly, jadi tidak ada baris audit
        // untuk sekadar penanda waktu.
        $this->assertSame($before, AuditLog::query()->count());
    }

    /**
     * Email tidak dikenal dan password salah menghasilkan pesan yang sama:
     * membedakannya memberi tahu penyerang alamat mana yang terdaftar.
     */
    public function test_bad_credentials_are_rejected_with_an_indistinguishable_message(): void
    {
        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'SalahSekali9',
        ])->assertStatus(422);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'tidakada@sekolah.test',
            'password' => 'RahasiaKuat1',
        ])->assertStatus(422);

        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
        $this->assertSame(['Email atau password salah.'], $wrongPassword->json('errors.email'));
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $this->bendahara->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'RahasiaKuat1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Akun Anda tidak aktif.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Arsitektur 3.4 — "Login: max 5 percobaan/menit".
     */
    public function test_login_is_throttled_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'bendahara@sekolah.test',
                'password' => 'SalahSekali9',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'bendahara@sekolah.test',
            'password' => 'SalahSekali9',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    // ------------------------------------------------------------ me/logout

    public function test_me_returns_the_authenticated_profile(): void
    {
        $this->withToken($this->tokenFor($this->bendahara))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->bendahara->id)
            ->assertJsonPath('data.role', RoleName::Bendahara->value)
            ->assertJsonPath('data.school.id', $this->school->id);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $first = $this->tokenFor($this->bendahara);
        $second = $this->tokenFor($this->bendahara);

        $this->withToken($first)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(PersonalAccessToken::findToken($first));
        // Perangkat lain tidak ikut dikeluarkan — blueprint menulis "token sesi
        // aktif", tunggal.
        $this->assertNotNull(PersonalAccessToken::findToken($second));
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $token = $this->tokenFor($this->bendahara);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->forgetResolvedUser();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    /**
     * Token yang sudah terbit tidak otomatis gugur saat akun dinonaktifkan,
     * jadi status aktifnya diperiksa di setiap request.
     */
    public function test_a_token_of_a_deactivated_account_stops_working(): void
    {
        $token = $this->tokenFor($this->bendahara);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->bendahara->forceFill(['is_active' => false])->save();

        $this->forgetResolvedUser();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif.');
    }

    // ------------------------------------------------------------ contract

    public function test_the_success_envelope_matches_the_convention(): void
    {
        $body = $this->withToken($this->tokenFor($this->bendahara))
            ->getJson('/api/v1/auth/me')
            ->json();

        $this->assertSame(['success', 'data', 'message'], array_keys($body));
        $this->assertTrue($body['success']);
    }

    public function test_the_validation_envelope_matches_the_convention(): void
    {
        $body = $this->postJson('/api/v1/auth/login', ['email' => 'bukan-email'])->assertStatus(422)->json();

        $this->assertSame(['success', 'message', 'errors'], array_keys($body));
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('email', $body['errors']);
        $this->assertArrayHasKey('password', $body['errors']);
    }

    public function test_the_pagination_envelope_matches_the_convention(): void
    {
        $body = $this->withToken($this->tokenFor($this->bendahara))
            ->getJson('/api/v1/fee-types')
            ->assertOk()
            ->json();

        $this->assertSame(['success', 'data', 'meta', 'message'], array_keys($body));
        $this->assertSame(['total', 'page', 'per_page', 'last_page'], array_keys($body['meta']));
    }

    public function test_an_unauthorized_action_returns_a_403_envelope(): void
    {
        $guru = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $body = $this->withToken($this->tokenFor($guru))
            ->getJson('/api/v1/fee-types')
            ->assertStatus(403)
            ->json();

        $this->assertSame(['success', 'message', 'errors'], array_keys($body));
        $this->assertFalse($body['success']);
    }

    public function test_a_missing_record_returns_a_404_envelope(): void
    {
        $this->withToken($this->tokenFor($this->bendahara))
            ->getJson('/api/v1/student-fees/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Data tidak ditemukan.');
    }

    /**
     * Galat tak terduga tidak boleh membawa pesan exception maupun jejak
     * tumpukan — keduanya dapat memuat nama tabel dan jalur berkas server.
     */
    public function test_server_errors_never_leak_a_stack_trace(): void
    {
        config(['app.debug' => false]);

        Route::middleware('api')->get('/api/v1/__meledak', function (): void {
            throw new \RuntimeException('SELECT * FROM users WHERE rahasia');
        });

        $body = $this->getJson('/api/v1/__meledak')->assertStatus(500)->json();

        $this->assertSame('Terjadi kesalahan pada server.', $body['message']);
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertStringNotContainsString('rahasia', json_encode($body));
    }

    public function test_timestamps_use_iso_8601(): void
    {
        $lastLogin = $this->withToken($this->tokenFor($this->bendahara))
            ->getJson('/api/v1/auth/me')
            ->json('data.last_login_at');

        if ($lastLogin !== null) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $lastLogin,
            );
        }

        $this->bendahara->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->forgetResolvedUser();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $this->withToken($this->tokenFor($this->bendahara))
                ->getJson('/api/v1/auth/me')
                ->json('data.last_login_at'),
        );
    }

    public function test_password_hashing_still_matches_the_stored_hash(): void
    {
        $this->assertTrue(Hash::check('RahasiaKuat1', $this->bendahara->password));
    }
}
