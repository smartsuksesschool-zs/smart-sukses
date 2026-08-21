<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleName;
use App\Enums\StudentClassStatus;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Livewire\Portal\ParentDashboard;
use App\Livewire\Portal\PortalLogin;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentFee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PORTAL-01 — halaman Parent Portal.
 *
 * Portal ini rute web biasa dengan guard `web` yang sama dengan panel, bukan
 * panel Filament kedua (butir 147). Yang dijaga di sini: orang tua masuk ke
 * portal dan **tidak** ke panel, peran lain tidak mendapat portal, dan pemilih
 * anak tidak dapat dipakai untuk melihat anak orang lain.
 */
class ParentPortalUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected AcademicYear $yearA;

    protected User $parentA;

    protected Student $childOne;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create([
            'name' => 'SMP Madani',
            'primary_color' => '#123456',
        ]);
        $this->schoolB = School::factory()->create();

        $this->yearA = AcademicYear::factory()->create([
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);

        $this->parentA = $this->userIn($this->schoolA, RoleName::OrangTua, ['name' => 'Ibu Sari']);
        $this->childOne = $this->childOf($this->parentA, $this->schoolA, 'Ahmad Fauzi');
    }

    protected function userIn(School $school, RoleName $role, array $overrides = []): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create($overrides);
    }

    protected function childOf(?User $parent, School $school, string $name, array $overrides = []): Student
    {
        return Student::factory()->create([
            'school_id' => $school->id,
            'parent_user_id' => $parent?->getKey(),
            'full_name' => $name,
            'status' => StudentStatus::Active->value,
            ...$overrides,
        ]);
    }

    protected function feeFor(Student $student, string $amount, StudentFeeStatus $status = StudentFeeStatus::Unpaid): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $student->school_id])->id,
            'amount' => $amount,
            'amount_paid' => '0',
            'period' => '2026-08',
            'status' => $status->value,
        ]);
    }

    // ------------------------------------------------------------- akses

    public function test_a_parent_reaches_the_portal_dashboard(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Ahmad Fauzi');
    }

    public function test_a_guest_is_sent_to_the_portal_login(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect();

        $this->get(route('portal.login'))->assertOk()->assertSee('Portal Orang Tua');
    }

    /**
     * Aturan akses panel yang sudah ada tidak dilonggarkan sedikit pun: orang
     * tua tetap ditolak seluruh panel admin (butir 147).
     */
    public function test_a_parent_still_cannot_reach_the_admin_panel(): void
    {
        $this->assertFalse($this->parentA->canAccessPanel(filament()->getPanel('admin')));

        $this->actingAs($this->parentA);

        $this->get(Dashboard::getUrl())->assertForbidden();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonParentRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
            'siswa' => [RoleName::Siswa],
        ];
    }

    #[DataProvider('nonParentRoles')]
    public function test_no_other_role_gets_the_parent_dashboard(RoleName $role): void
    {
        $this->actingAs($this->userIn($this->schoolA, $role));

        $this->get(route('portal.dashboard'))->assertForbidden();
    }

    public function test_an_admin_role_keeps_its_panel_access(): void
    {
        $admin = $this->userIn($this->schoolA, RoleName::SchoolAdmin);

        // Portal untuk orang tua tidak boleh menutup panel bagi peran lama.
        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_a_deactivated_parent_cannot_open_the_portal(): void
    {
        $inactive = $this->userIn($this->schoolA, RoleName::OrangTua, ['is_active' => false]);
        $this->childOf($inactive, $this->schoolA, 'Anak Nonaktif');

        $this->actingAs($inactive);

        $this->get(route('portal.dashboard'))->assertForbidden();
    }

    public function test_a_school_less_parent_cannot_open_the_portal(): void
    {
        $orphan = User::factory()->withRole(RoleName::OrangTua)->create(['school_id' => null]);

        $this->actingAs($orphan);

        $this->get(route('portal.dashboard'))->assertForbidden();
    }

    // -------------------------------------------------------------- login

    public function test_a_parent_signs_in_through_the_portal_login(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
        ]);
        $this->childOf($parent, $this->schoolA, 'Anak Login');

        Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($parent);
    }

    /**
     * Kredensial benar tetapi peran keliru: tidak boleh tertinggal sesi
     * setengah masuk.
     */
    public function test_a_non_parent_cannot_sign_in_to_the_portal(): void
    {
        $this->userIn($this->schoolA, RoleName::SchoolAdmin, [
            'email' => 'admin@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        Livewire::test(PortalLogin::class)
            ->set('email', 'admin@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_indistinguishable_from_an_unknown_email(): void
    {
        $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        $wrongPassword = Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'salah')
            ->call('authenticate')
            ->errors()->get('email');

        $unknownEmail = Livewire::test(PortalLogin::class)
            ->set('email', 'tidakada@example.test')
            ->set('password', 'apa saja')
            ->call('authenticate')
            ->errors()->get('email');

        $this->assertSame($wrongPassword, $unknownEmail);
        $this->assertGuest();
    }

    public function test_logging_out_returns_to_the_login_page(): void
    {
        $this->actingAs($this->parentA);

        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

        $this->assertGuest();
    }

    // ------------------------------------------------------- multi anak

    public function test_a_single_child_is_selected_without_a_switcher(): void
    {
        $this->actingAs($this->parentA);

        Livewire::test(ParentDashboard::class)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Pilih anak');
    }

    public function test_more_than_one_child_shows_a_switcher(): void
    {
        $second = $this->childOf($this->parentA, $this->schoolA, 'Budi Santoso');

        $this->actingAs($this->parentA);

        Livewire::test(ParentDashboard::class)
            ->assertSee('Ahmad Fauzi')
            ->assertSee('Budi Santoso')
            ->assertSeeHtml('wire:click="selectChild('.$second->id.')"');
    }

    public function test_switching_child_changes_the_summary(): void
    {
        $second = $this->childOf($this->parentA, $this->schoolA, 'Budi Santoso');

        $this->feeFor($this->childOne, '100000');
        $this->feeFor($second, '750000');

        $this->actingAs($this->parentA);

        $component = Livewire::test(ParentDashboard::class);

        $component->assertSee('100.000');

        $component->call('selectChild', $second->id)
            ->assertSet('selectedChildId', $second->id)
            ->assertSee('750.000');
    }

    /**
     * Pemilih anak tidak boleh menjadi jalan masuk ke anak orang lain: id yang
     * bukan miliknya diabaikan, dan pilihannya tetap pada anak sebelumnya
     * (butir 156).
     */
    public function test_a_crafted_child_id_cannot_be_selected(): void
    {
        $otherParent = $this->userIn($this->schoolA, RoleName::OrangTua);
        $foreign = $this->childOf($otherParent, $this->schoolA, 'Anak Orang Lain');

        $this->feeFor($foreign, '999999');

        $this->actingAs($this->parentA);

        Livewire::test(ParentDashboard::class)
            ->call('selectChild', $foreign->id)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Anak Orang Lain')
            ->assertDontSee('999.999');
    }

    public function test_a_child_from_another_branch_cannot_be_selected(): void
    {
        $foreign = $this->childOf($this->parentA, $this->schoolB, 'Anak Cabang Lain');

        $this->actingAs($this->parentA);

        Livewire::test(ParentDashboard::class)
            ->call('selectChild', $foreign->id)
            ->assertSet('selectedChildId', $this->childOne->id)
            ->assertDontSee('Anak Cabang Lain');
    }

    public function test_a_parent_without_linked_children_sees_an_explanation(): void
    {
        $lonely = $this->userIn($this->schoolA, RoleName::OrangTua);

        $this->actingAs($lonely);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Belum ada data anak');
    }

    // ---------------------------------------------------------- tampilan

    public function test_the_dashboard_shows_the_pending_fee_amount_and_count(): void
    {
        $this->feeFor($this->childOne, '400000');
        $this->feeFor($this->childOne, '600000');
        $this->feeFor($this->childOne, '900000', StudentFeeStatus::Waived);

        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('1.000.000')
            ->assertSee('2 tagihan menunggu pembayaran')
            ->assertDontSee('1.900.000');
    }

    /**
     * PORTAL-01 meminta kehadiran bulan ini, dan sumbernya tidak ada di
     * Phase 1. Yang tampil kalimat sebenarnya, bukan "0 hadir" (butir 152).
     */
    public function test_the_dashboard_says_attendance_is_unavailable(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Data kehadiran belum tersedia')
            ->assertDontSee('0 hadir');
    }

    public function test_the_active_class_is_shown_when_the_child_has_one(): void
    {
        $class = SchoolClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'academic_year_id' => $this->yearA->id,
            'name' => '7A',
        ]);
        StudentClass::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => $this->childOne->id,
            'class_id' => $class->id,
            'academic_year_id' => $this->yearA->id,
            'status' => StudentClassStatus::Active->value,
        ]);

        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))->assertOk()->assertSee('Kelas 7A');
    }

    public function test_a_child_without_a_class_is_stated_plainly(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('belum terdaftar di kelas pada tahun ajaran aktif');
    }

    // ------------------------------------------------ branding & mobile

    public function test_the_portal_carries_the_branding_of_the_parents_school(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('SMP Madani')
            ->assertSee('--color-primary: #123456', false);
    }

    public function test_one_school_branding_does_not_leak_into_another(): void
    {
        $otherParent = $this->userIn($this->schoolB, RoleName::OrangTua);
        $this->childOf($otherParent, $this->schoolB, 'Anak Cabang B');

        $this->actingAs($otherParent);

        $this->get(route('portal.dashboard'))
            ->assertOk()
            ->assertDontSee('SMP Madani')
            ->assertDontSee('#123456', false);
    }

    /**
     * PORTAL-01 poin 3 — responsive mobile. Yang dapat diperiksa test HTTP
     * adalah strukturnya: viewport yang benar, tata letak satu kolom sebagai
     * bawaan, dan tidak ada elemen yang mendorong halaman melebar.
     */
    public function test_the_portal_markup_is_built_mobile_first(): void
    {
        $this->actingAs($this->parentA);

        $response = $this->get(route('portal.dashboard'))->assertOk();

        $response->assertSee('width=device-width, initial-scale=1', false);
        // Satu kolom sebagai bawaan; kolom jamak hanya di dalam media query.
        $response->assertSee('grid-template-columns: 1fr', false);
        $response->assertSee('@media (min-width: 48rem)', false);
        $response->assertSee('overflow-x: hidden', false);
        // Sasaran sentuh yang layak.
        $response->assertSee('min-height: 2.75rem', false);
    }

    // ----------------------------------------------- keamanan sesi masuk

    /**
     * Fiksasi sesi: id sesi sebelum masuk tidak boleh masih berlaku sesudahnya
     * (butir 157).
     */
    public function test_the_session_id_is_regenerated_after_a_successful_login(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
        ]);
        $this->childOf($parent, $this->schoolA, 'Anak Sesi');

        $this->startSession();
        $before = session()->getId();

        Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($parent);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedAccounts(): array
    {
        return [
            'nonaktif' => [['is_active' => false]],
            'wajib ganti kata sandi' => [['must_change_password' => true]],
            'tanpa cabang' => [['school_id' => null]],
        ];
    }

    /**
     * Kredensialnya benar, tetapi akunnya tidak berhak: tidak boleh ada sesi
     * yang tertinggal (butir 157).
     *
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('refusedAccounts')]
    public function test_a_refused_account_is_never_left_authenticated(array $overrides): void
    {
        $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
            ...$overrides,
        ]);

        Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Arsitektur 3.4 berlaku untuk seluruh pengguna, bukan hanya pengguna
     * panel. Portal tidak boleh menjadi jalan memutarnya (butir 158).
     */
    public function test_a_temporary_password_cannot_be_used_to_browse_the_portal(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, [
            'must_change_password' => true,
        ]);
        $this->childOf($parent, $this->schoolA, 'Anak Sandi Sementara');

        $this->actingAs($parent);

        $this->get(route('portal.dashboard'))->assertForbidden();
    }

    /**
     * Penanda itu dapat menyala setelah sesi terbentuk — admin mereset
     * password pengguna yang sedang login (PORTAL-04).
     */
    public function test_an_existing_session_stops_once_a_password_reset_is_required(): void
    {
        $this->actingAs($this->parentA);

        $this->get(route('portal.dashboard'))->assertOk();

        $this->parentA->forceFill(['must_change_password' => true])->save();

        $this->get(route('portal.dashboard'))->assertForbidden();
    }

    /**
     * Jalan keluarnya alur yang sudah ada: karena akun itu tidak pernah diberi
     * sesi, rute tamu "lupa kata sandi" selalu dapat dijangkau, dan mengganti
     * password melepas penandanya lewat hook pada User.
     */
    public function test_setting_a_new_password_clears_the_requirement(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, [
            'must_change_password' => true,
        ]);

        $this->get(route('filament.admin.auth.password-reset.request'))->assertOk();

        $parent->forceFill(['password' => bcrypt('sandibaru123')])->save();

        $this->assertFalse($parent->fresh()->must_change_password);
    }

    // --------------------------------------------------- keluar & sesi

    public function test_logging_out_invalidates_the_session_and_rotates_the_token(): void
    {
        $this->actingAs($this->parentA);
        $this->startSession();

        $sessionBefore = session()->getId();
        $tokenBefore = session()->token();

        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));

        $this->assertGuest();
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_the_portal_cannot_be_reached_again_after_logging_out(): void
    {
        $this->actingAs($this->parentA);

        $this->post(route('portal.logout'));

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    public function test_there_is_no_get_logout_route(): void
    {
        $this->actingAs($this->parentA);

        // 405: rutenya hanya menerima POST, sehingga tidak dapat dipicu lewat
        // tautan maupun prefetch peramban.
        $this->get('/portal/keluar')->assertStatus(405);
    }

    /**
     * Rute POST portal berada di grup `web`, jadi CSRF-nya dijaga middleware
     * yang sama dengan seluruh aplikasi.
     */
    public function test_the_logout_route_is_csrf_protected(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'portal.logout');

        $this->assertContains('web', $route->gatherMiddleware());

        $this->assertContains(
            ValidateCsrfToken::class,
            app('router')->getMiddlewareGroups()['web'],
        );
    }

    // ------------------------------------------------ pengalihan aman

    public function test_a_guest_is_redirected_rather_than_erroring(): void
    {
        $response = $this->get(route('portal.dashboard'));

        $response->assertRedirect(route('portal.login'));
        $this->assertNotSame(500, $response->getStatusCode());
    }

    public function test_an_authenticated_non_parent_does_not_loop(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        // Halaman masuk mengalihkan ke beranda, bukan kembali ke dirinya
        // sendiri.
        $target = $this->get(route('portal.login'))->headers->get('Location');

        $this->assertNotSame(route('portal.login'), $target);
        $this->assertStringStartsWith(config('app.url'), $target);
    }

    /**
     * Tidak ada open redirect: tujuannya rute internal yang ditulis pasti,
     * bukan nilai dari query string.
     */
    public function test_no_query_parameter_can_steer_the_redirect(): void
    {
        $parent = $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
        ]);
        $this->childOf($parent, $this->schoolA, 'Anak Redirect');

        foreach (['redirect', 'next', 'return', 'intended'] as $parameter) {
            $this->get(route('portal.login').'?'.$parameter.'=https://jahat.example.com')
                ->assertOk();
        }

        Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_the_login_throttle_still_stops_at_five_attempts(): void
    {
        $this->userIn($this->schoolA, RoleName::OrangTua, [
            'email' => 'ortu@example.test',
            'password' => bcrypt('rahasia123'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::test(PortalLogin::class)
                ->set('email', 'ortu@example.test')
                ->set('password', 'salah')
                ->call('authenticate')
                ->assertHasErrors('email');
        }

        // Percobaan keenam ditolak karena batasnya, bukan karena kata sandinya
        // — bahkan dengan kata sandi yang benar.
        $errors = Livewire::test(PortalLogin::class)
            ->set('email', 'ortu@example.test')
            ->set('password', 'rahasia123')
            ->call('authenticate')
            ->errors()->get('email');

        $this->assertStringContainsString('Terlalu banyak percobaan', $errors[0]);
        $this->assertGuest();
    }
}
