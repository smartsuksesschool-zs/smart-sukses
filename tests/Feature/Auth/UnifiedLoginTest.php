<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Livewire\Auth\Login;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Login as LoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pintu masuk tunggal.
 *
 * Keputusan pemilik: pengunjung tidak lagi memilih perannya sebelum masuk.
 * Ia mengetikkan kredensial, dan **server** menentukan tujuannya dari peran
 * yang tersimpan di akun (butir 437).
 *
 * Yang paling penting dijaga di sini bukan bahwa pengalihannya benar, melainkan
 * bahwa perannya tidak pernah dapat dipengaruhi dari luar: tidak dari URL,
 * tidak dari query string, dan tidak dari payload Livewire.
 */
class UnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create(['code' => 'MADANI', 'is_active' => true]);
    }

    protected function accountWith(RoleName $role, array $overrides = []): User
    {
        $user = User::factory()->forSchool($this->school)->withRole($role)->create([
            'email' => 'akun@example.test',
            'password' => bcrypt('rahasia123'),
            ...$overrides,
        ]);

        // Siswa dan orang tua menuntut baris siswa yang terkait.
        if ($role === RoleName::Siswa) {
            Student::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);
        }

        if ($role === RoleName::OrangTua) {
            Student::factory()->create(['school_id' => $this->school->id, 'parent_user_id' => $user->id]);
        }

        return $user;
    }

    protected function signIn(string $email = 'akun@example.test', string $password = 'rahasia123'): Testable
    {
        return Livewire::test(Login::class)
            ->set('email', $email)
            ->set('password', $password)
            ->call('authenticate');
    }

    /**
     * Dasbor panel, dinyatakan lewat nama rutenya.
     *
     * `Panel::getUrl()` sengaja tidak dipakai di sini: bagi tamu ia
     * mengembalikan URL halaman masuk, bukan dasbor — benar di dalam komponen
     * (di sana penggunanya sudah terautentikasi), menyesatkan di dalam tes.
     */
    protected function panelUrl(): string
    {
        return route('filament.admin.pages.dashboard');
    }

    // ===================================================== A. rute & tampilan

    public function test_the_login_page_is_public(): void
    {
        $this->get(route('login'))->assertOk();
        $this->assertSame('/login', route('login', absolute: false));
    }

    /**
     * Tidak ada pemilih peran — dan yang menjadikannya aman bukan ketiadaan
     * field itu, melainkan bahwa resolusinya tidak pernah membaca request.
     */
    public function test_the_form_offers_no_role_selector(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        foreach (['name="role"', 'wire:model="role"', 'Masuk sebagai', 'Login as', '<select'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Pemilih peran muncul: {$needle}");
        }
    }

    public function test_the_language_switch_is_available_before_signing_in(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('locale-switch', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    /**
     * SMTP belum diadakan, jadi pemulihan kata sandi belum ditawarkan —
     * menjanjikan surel yang tidak akan pernah terkirim lebih buruk daripada
     * diam (butir 441).
     */
    public function test_no_password_recovery_is_advertised(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        foreach (['Lupa kata sandi', 'Forgot password', 'password-reset'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_wrong_credentials_fail_uniformly(): void
    {
        $this->accountWith(RoleName::Siswa);

        $wrongPassword = $this->signIn(password: 'salah-sekali')->errors()->get('email');
        $unknownEmail = $this->signIn(email: 'entah@example.test')->errors()->get('email');

        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertGuest();
    }

    // ================================================== B. tujuan per peran

    /**
     * @return array<string, array{RoleName, string}>
     */
    public static function everyRole(): array
    {
        return [
            'siswa' => [RoleName::Siswa, 'student.dashboard'],
            'orang tua' => [RoleName::OrangTua, 'portal.dashboard'],
            'guru' => [RoleName::Guru, 'panel'],
            'wali kelas' => [RoleName::WaliKelas, 'panel'],
            'bendahara' => [RoleName::Bendahara, 'panel'],
            'kepala sekolah' => [RoleName::KepalaSekolah, 'panel'],
            'admin sekolah' => [RoleName::SchoolAdmin, 'panel'],
        ];
    }

    #[DataProvider('everyRole')]
    public function test_each_role_reaches_its_own_destination(RoleName $role, string $destination): void
    {
        $user = $this->accountWith($role);

        $expected = $destination === 'panel' ? $this->panelUrl() : route($destination);

        $this->signIn()->assertHasNoErrors()->assertRedirect($expected);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Super Admin sengaja tanpa cabang (Arsitektur 3.2.2), jadi ia diuji
     * terpisah dari peran yang menuntut `school_id`.
     */
    public function test_a_super_admin_reaches_the_panel(): void
    {
        $user = User::factory()->withRole(RoleName::SuperAdmin)->create([
            'school_id' => null,
            'email' => 'akun@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        $this->signIn()->assertHasNoErrors()->assertRedirect($this->panelUrl());

        $this->assertAuthenticatedAs($user);
    }

    // ============================================ C. integritas & keamanan

    public function test_an_account_without_a_role_is_refused(): void
    {
        User::factory()->forSchool($this->school)->create([
            'email' => 'akun@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        $this->signIn()->assertHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Aturan produknya tepat satu peran (PRD 1.1.1). Dua peran karena itu
     * bukan keadaan yang boleh ditebak tujuannya — ia cacat data, dan ditolak
     * (butir 439).
     */
    public function test_an_account_with_two_roles_is_refused(): void
    {
        $user = $this->accountWith(RoleName::Guru);
        $user->syncRoles([RoleName::Guru->value, RoleName::Bendahara->value]);

        $this->assertSame(2, $user->roles()->count());

        $this->signIn()->assertHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Nilai `role` yang dipalsukan ke payload Livewire tidak punya satu pun
     * tempat untuk masuk: komponennya tidak memiliki properti itu, dan
     * penentuan tujuan tidak pernah membaca request.
     */
    public function test_a_forged_role_in_the_payload_is_ignored(): void
    {
        $this->accountWith(RoleName::Siswa);

        Livewire::withQueryParams(['role' => RoleName::SuperAdmin->value])
            ->test(Login::class)
            ->set('email', 'akun@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertRedirect(route('student.dashboard'));
    }

    #[DataProvider('portalRoles')]
    public function test_a_portal_credential_never_reaches_the_panel(RoleName $role): void
    {
        $user = $this->accountWith($role);

        $this->signIn()->assertHasNoErrors();

        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));

        $this->actingAs($user)->get($this->panelUrl())->assertForbidden();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function portalRoles(): array
    {
        return ['siswa' => [RoleName::Siswa], 'orang tua' => [RoleName::OrangTua]];
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->accountWith(RoleName::Guru, ['is_active' => false]);

        $this->signIn()->assertHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Syarat kelayakan portal tidak dilonggarkan: akun portal tanpa cabang
     * tetap ditolak (butir 127).
     */
    public function test_portal_eligibility_is_still_enforced(): void
    {
        $user = User::factory()->withRole(RoleName::Siswa)->create([
            'school_id' => null,
            'email' => 'akun@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        Student::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);

        $this->signIn()->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_session_id_is_regenerated_after_a_successful_login(): void
    {
        $this->accountWith(RoleName::Guru);

        $this->get(route('login'));
        $before = session()->getId();

        $this->signIn()->assertHasNoErrors();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_login_event_still_fires_and_stamps_the_account(): void
    {
        Event::fake([LoginEvent::class]);

        $this->accountWith(RoleName::Guru);

        $this->signIn()->assertHasNoErrors();

        Event::assertDispatched(LoginEvent::class);
    }

    public function test_last_login_at_is_recorded(): void
    {
        $user = $this->accountWith(RoleName::Guru);

        $this->assertNull($user->last_login_at);

        $this->signIn()->assertHasNoErrors();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * Lima percobaan per menit, satu ruang nama untuk seluruh peran. Ketiga
     * pintu lama memakai kuncinya sendiri, sehingga satu surel sebenarnya
     * punya lima belas percobaan (butir 440).
     */
    public function test_the_throttle_still_stops_at_five_attempts(): void
    {
        $this->accountWith(RoleName::Guru);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->signIn(password: 'salah')->assertHasErrors('email');
        }

        $errors = $this->signIn()->errors()->get('email');

        $this->assertStringContainsString('Terlalu banyak percobaan masuk', $errors[0]);
        $this->assertGuest();

        RateLimiter::clear('login:akun@example.test|127.0.0.1');
    }

    /**
     * Kata sandi sementara tidak boleh menjadi jalan memutar. Bagi portal ia
     * ditolak dengan petunjuk yang sudah ada sejak butir 158; bagi staf,
     * `EnsurePasswordIsChanged` di dalam panel yang mengambil alih — logika itu
     * tidak digandakan di halaman masuk (butir 438).
     */
    public function test_a_temporary_password_stops_a_portal_account(): void
    {
        $this->accountWith(RoleName::Siswa, ['must_change_password' => true]);

        $errors = $this->signIn()->assertHasErrors('email')->errors()->get('email');

        $this->assertStringContainsString('Kata sandi sementara', $errors[0]);
        $this->assertGuest();
    }

    public function test_a_staff_account_with_a_temporary_password_is_intercepted_inside_the_panel(): void
    {
        $user = $this->accountWith(RoleName::Guru, ['must_change_password' => true]);

        $this->signIn()->assertHasNoErrors()->assertRedirect($this->panelUrl());

        // Panel tidak membiarkannya sampai ke dasbor.
        $this->actingAs($user)->get($this->panelUrl())->assertRedirect();
    }

    // ================================================== D. kompatibilitas

    public function test_the_legacy_doors_still_resolve(): void
    {
        $this->get('/siswa/masuk')->assertRedirect(route('login'));
        $this->get('/portal/masuk')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertRedirect(route('login'));

        // Nama rute lama tetap resolve — tiga belas tempat merujuknya.
        $this->assertNotNull(app('router')->getRoutes()->getByName('filament.admin.auth.login'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('student.login'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('portal.login'));
    }

    public function test_a_filament_guest_is_led_to_the_unified_login(): void
    {
        // Filament mengalihkan tamu ke halaman masuknya sendiri…
        $this->get($this->panelUrl())
            ->assertRedirect(route('filament.admin.auth.login'));

        // …dan halaman itu kini mengantar ke pintu tunggal (butir 444).
        $this->get(route('filament.admin.auth.login'))
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertOk();
    }

    // ========================================================= E. halaman muka

    public function test_the_landing_sends_every_role_to_the_single_door(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Navbar (desktop + bar seluler), hero, tiga kartu peran, tiga tautan footer.
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'href="'.route('login').'"'));

        // PPDB tetap pintu terpisah dan tetap publik.
        $this->assertStringContainsString('href="'.route('ppdb.schools').'"', $html);
    }
}
