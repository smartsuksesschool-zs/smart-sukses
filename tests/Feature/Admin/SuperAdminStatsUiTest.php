<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PpdbStatus;
use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\SchoolResource\Pages\ViewSchool;
use App\Filament\Resources\SchoolResource\Widgets\SchoolStatsOverview;
use App\Filament\Widgets\PlatformStatsOverview;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use App\Services\Admin\SchoolStatisticsService;
use App\Services\Admin\SuperAdminDashboardService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Alur Super Admin — dashboard ringkasan seluruh cabang, dan statistik satu
 * cabang di halaman detailnya.
 *
 * Yang dijaga di sini bukan hanya "kartunya muncul", melainkan bahwa angkanya
 * berasal dari service yang sama dengan API: kalau widget menghitung sendiri,
 * layar dan API akan mulai berbeda tanpa ada yang menyadarinya.
 */
class SuperAdminStatsUiTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->superAdmin = User::factory()->withRole(RoleName::SuperAdmin)
            ->create(['school_id' => null]);
    }

    protected function userIn(School $school, RoleName $role): User
    {
        return User::factory()->forSchool($school)->withRole($role)->create();
    }

    protected function seedSomeData(): void
    {
        Student::factory()->count(3)->create([
            'school_id' => $this->schoolA->id,
            'status' => StudentStatus::Active->value,
        ]);

        $this->userIn($this->schoolA, RoleName::Guru);
        $this->userIn($this->schoolA, RoleName::WaliKelas);

        $fee = StudentFee::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_id' => Student::factory()->create(['school_id' => $this->schoolA->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $this->schoolA->id])->id,
            'amount' => '1000000',
            'amount_paid' => '250000',
            'period' => '2026-08',
            'status' => StudentFeeStatus::Partial->value,
        ]);

        Payment::factory()->create([
            'school_id' => $this->schoolA->id,
            'student_fee_id' => $fee->id,
            'student_id' => $fee->student_id,
            'amount_paid' => '250000',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'payment_method' => PaymentMethod::Cash->value,
            'received_by' => $this->userIn($this->schoolA, RoleName::Bendahara)->id,
        ]);

        PpdbRegistration::factory()->create([
            'school_id' => $this->schoolA->id,
            'status' => PpdbStatus::Registered->value,
        ]);
    }

    // -------------------------------------------------- dashboard platform

    public function test_the_super_admin_dashboard_shows_the_three_metrics(): void
    {
        $this->seedSomeData();
        $this->actingAs($this->superAdmin);

        Livewire::test(PlatformStatsOverview::class)
            ->assertSee('Total Siswa')
            ->assertSee('Total SPP Terkumpul')
            ->assertSee('PPDB Aktif');
    }

    public function test_the_dashboard_numbers_match_the_service(): void
    {
        $this->seedSomeData();
        $this->actingAs($this->superAdmin);

        $summary = app(SuperAdminDashboardService::class)->summarize($this->superAdmin);

        // 4 siswa aktif, Rp 250.000 diterima, 1 pendaftaran berjalan.
        $this->assertSame(4, $summary['total_students']);
        $this->assertSame('250000.00', $summary['total_spp_collected']);
        $this->assertSame(1, $summary['active_ppdb']);

        Livewire::test(PlatformStatsOverview::class)
            ->assertSee('4')
            ->assertSee('250.000')
            ->assertSee('1');
    }

    public function test_the_widget_is_visible_to_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(PlatformStatsOverview::canView());
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function nonSuperRoles(): array
    {
        return [
            'school admin' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
        ];
    }

    #[DataProvider('nonSuperRoles')]
    public function test_the_platform_widget_is_hidden_from_everyone_else(RoleName $role): void
    {
        $this->actingAs($this->userIn($this->schoolA, $role));

        $this->assertFalse(PlatformStatsOverview::canView());
        $this->assertFalse(SchoolStatsOverview::canView());
    }

    public function test_a_guest_never_sees_the_widget(): void
    {
        $this->assertFalse(PlatformStatsOverview::canView());
        $this->assertFalse(SchoolStatsOverview::canView());
    }

    // ----------------------------------------------------- statistik cabang

    public function test_the_school_detail_page_shows_its_statistics(): void
    {
        $this->seedSomeData();
        $this->actingAs($this->superAdmin);

        Livewire::test(SchoolStatsOverview::class, ['record' => $this->schoolA])
            ->assertSee('Jumlah Siswa')
            ->assertSee('Jumlah Guru')
            ->assertSee('Terkumpul Bulan Ini')
            ->assertSee('Tunggakan');
    }

    public function test_the_school_stats_numbers_match_the_service(): void
    {
        $this->seedSomeData();
        $this->actingAs($this->superAdmin);

        $stats = app(SchoolStatisticsService::class)
            ->forSchool($this->schoolA->getKey(), $this->superAdmin);

        $this->assertSame(4, $stats['student_count']);
        $this->assertSame(2, $stats['teacher_count']);
        $this->assertSame('250000.00', $stats['collected_this_month']);
        $this->assertSame('750000.00', $stats['arrears']);

        Livewire::test(SchoolStatsOverview::class, ['record' => $this->schoolA])
            ->assertSee('4')
            ->assertSee('2')
            ->assertSee('250.000')
            ->assertSee('750.000');
    }

    /**
     * Widget Filament dimuat malas, jadi labelnya belum ada di HTML pertama;
     * yang dipastikan di sini adalah halamannya benar-benar memasang komponen
     * itu sebagai header widget.
     */
    public function test_the_stats_widget_is_wired_into_the_school_detail_page(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get(ViewSchool::getUrl(['record' => $this->schoolA]))->assertOk();

        Livewire::test(ViewSchool::class, ['record' => $this->schoolA->getKey()])
            ->assertSeeLivewire(SchoolStatsOverview::class);
    }

    /**
     * Cabang yang sudah ditutup tetap dapat dibaca statistiknya (butir 144).
     */
    public function test_an_inactive_school_can_still_be_viewed(): void
    {
        $closed = School::factory()->create(['is_active' => false]);

        Student::factory()->create([
            'school_id' => $closed->id,
            'status' => StudentStatus::Active->value,
        ]);

        $this->actingAs($this->superAdmin);

        Livewire::test(SchoolStatsOverview::class, ['record' => $closed])
            ->assertSee('Jumlah Siswa');
    }

    /**
     * Manajemen cabang memang hanya untuk Super Admin, jadi School Admin tidak
     * pernah sampai ke halaman itu — apalagi ke statistiknya.
     */
    public function test_a_school_admin_cannot_reach_the_school_detail_page(): void
    {
        $this->actingAs($this->userIn($this->schoolA, RoleName::SchoolAdmin));

        $this->get(ViewSchool::getUrl(['record' => $this->schoolA]))
            ->assertForbidden();
    }
}
