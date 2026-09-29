<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AUTH-04 AC-3 / CON-30 — seluruh sesi aktif di-invalidate setelah reset.
 *
 * Requirement-nya disebut di empat tempat pada snapshot sumber kebenaran:
 * `docs/blueprint/01-PRD.md:317` (CON-30), `:485` (AUTH-04 AC-3), `:1008`
 * (AC-M0-10), dan `docs/blueprint/03-USER_FLOW.md:917` (diagram alurnya). Ia
 * tidak ambigu, dan karena itu diuji sebagai requirement dan bukan sebagai
 * pilihan implementasi.
 *
 * Yang dibuktikan bukan "kode pencabutnya ada", melainkan bahwa sesi dan token
 * milik pengguna itu **benar-benar hilang** sesudah reset, dan bahwa milik
 * pengguna lain **tidak** ikut hilang (butir 591).
 */
class PasswordResetRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // `phpunit.xml` menyetel SESSION_DRIVER=array, sedangkan `.env.example`
        // dan berkas contoh produksi keduanya menyetel `database`. Yang perlu
        // diuji adalah perilaku produksi, jadi drivernya disamakan dengan
        // produksi — bukan listener-nya yang dilonggarkan agar lulus.
        config(['session.driver' => 'database']);

        $this->school = School::factory()->create(['code' => 'PUSAT']);
    }

    // ================================================== perkakas

    protected function staff(string $email): User
    {
        return User::factory()
            ->forSchool($this->school)
            ->withRole(RoleName::SchoolAdmin)
            ->create(['email' => $email]);
    }

    /**
     * Satu baris sesi seperti yang ditulis driver `database`.
     */
    protected function seedSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'uji',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    protected function sessionIdsOf(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->getKey())->pluck('id')->all();
    }

    // ================================================== end-to-end

    /**
     * Lewat halaman reset Filament yang sebenarnya, bukan lewat event langsung:
     * yang perlu dibuktikan adalah bahwa pencabutan itu ikut berjalan pada alur
     * yang benar-benar dipakai orang.
     */
    public function test_reset_lewat_halaman_filament_mencabut_sesi_dan_token(): void
    {
        $user = $this->staff('staf@example.test');

        $this->seedSession($user, 'sesi-perangkat-lama');
        $user->createToken('ponsel-lama');

        $this->assertCount(1, $this->sessionIdsOf($user));
        $this->assertSame(1, $user->tokens()->count());

        $token = Password::broker(config('filament.auth.password_broker'))->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm([
                'email' => $user->email,
                'password' => 'SandiBaruUji123',
                'passwordConfirmation' => 'SandiBaruUji123',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        // Sandinya memang berganti — tanpa ini, pencabutan di bawah tidak
        // membuktikan apa pun tentang reset.
        $this->assertTrue(password_verify('SandiBaruUji123', $user->fresh()->password));

        $this->assertSame([], $this->sessionIdsOf($user));
        $this->assertSame(0, $user->tokens()->count());
    }

    // ================================================== pagar lintas-pengguna

    public function test_pencabutan_tidak_menyentuh_pengguna_lain(): void
    {
        $user = $this->staff('yang-reset@example.test');
        $lain = $this->staff('yang-lain@example.test');

        $this->seedSession($user, 'sesi-milik-yang-reset');
        $this->seedSession($lain, 'sesi-milik-yang-lain');
        $user->createToken('token-yang-reset');
        $lain->createToken('token-yang-lain');

        event(new PasswordReset($user));

        $this->assertSame([], $this->sessionIdsOf($user));
        $this->assertSame(['sesi-milik-yang-lain'], $this->sessionIdsOf($lain));

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $lain->tokens()->count());
    }

    public function test_seluruh_sesi_pengguna_dicabut_bukan_hanya_satu(): void
    {
        $user = $this->staff('banyak-perangkat@example.test');

        $this->seedSession($user, 'sesi-laptop');
        $this->seedSession($user, 'sesi-ponsel');
        $this->seedSession($user, 'sesi-tablet');

        event(new PasswordReset($user));

        $this->assertSame([], $this->sessionIdsOf($user));
    }

    /**
     * Driver selain `database` tidak dapat dijangkau dari sini, dan yang penting
     * adalah ia **tidak memecahkan** apa pun — token tetap dicabut.
     */
    public function test_driver_sesi_bukan_database_tidak_memecahkan_pencabutan_token(): void
    {
        config(['session.driver' => 'file']);

        $user = $this->staff('driver-file@example.test');
        $this->seedSession($user, 'sesi-yang-tidak-terjangkau');
        $user->createToken('token-tetap-dicabut');

        event(new PasswordReset($user));

        $this->assertSame(0, $user->tokens()->count());

        // Barisnya tetap ada, dan itu memang keadaan yang jujur: penyimpanan
        // sesinya bukan basis data, sehingga tidak ada yang dapat dicabut dari
        // sini.
        $this->assertCount(1, $this->sessionIdsOf($user));
    }
}
