<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Enums\StudentFeeStatus;
use App\Filament\Pages\LaporanKeuangan;
use App\Filament\Pages\LaporanKeuanganCabang;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentFee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KAS-03 — dashboard lintas cabang, khusus Super Admin.
 */
class LaporanKeuanganCabangPageTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create(['name' => 'Cabang Alpha']);
        $this->schoolB = School::factory()->create(['name' => 'Cabang Beta']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function fee(School $school, array $overrides = []): StudentFee
    {
        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => Student::factory()->create(['school_id' => $school->id])->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
            'amount' => '1000000',
            'amount_paid' => '0',
            'status' => StudentFeeStatus::Unpaid->value,
            'period' => '2026-08',
            ...$overrides,
        ]);
    }

    // --------------------------------------------------------------- akses

    public function test_super_admin_opens_the_cross_branch_dashboard(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertTrue(LaporanKeuanganCabang::canAccess());
        $this->get(LaporanKeuanganCabang::getUrl())->assertSuccessful();
    }

    /**
     * KAS-03 menyebut pelakunya tunggal: Super Admin. Peran lain terikat
     * cabangnya sendiri dan tidak boleh melihat perbandingan antarcabang —
     * termasuk peran yang berwenang penuh atas keuangan cabangnya.
     *
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'admin sekolah' => [RoleName::SchoolAdmin],
            'kepala sekolah' => [RoleName::KepalaSekolah],
            'bendahara' => [RoleName::Bendahara],
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_every_school_level_role_is_rejected(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->assertFalse(LaporanKeuanganCabang::canAccess());
        $this->get(LaporanKeuanganCabang::getUrl())->assertForbidden();
        $this->get('/admin/laporan-keuangan-cabang')->assertForbidden();
    }

    public function test_students_and_parents_never_reach_it(): void
    {
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

            $this->get(LaporanKeuanganCabang::getUrl())->assertForbidden();
        }
    }

    /**
     * KAS-02 tetap milik peran cabang; KAS-03 tidak mengambil alih aksesnya.
     */
    public function test_kas_02_remains_available_to_branch_roles(): void
    {
        foreach ([RoleName::SchoolAdmin, RoleName::KepalaSekolah, RoleName::Bendahara] as $role) {
            $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

            $this->assertTrue(LaporanKeuangan::canAccess());
            $this->assertFalse(LaporanKeuanganCabang::canAccess());
        }
    }

    // ---------------------------------------------------------------- data

    public function test_the_page_lists_every_branch_with_its_numbers(): void
    {
        $this->fee($this->schoolA, ['amount' => '1000000', 'amount_paid' => '1000000', 'status' => StudentFeeStatus::Paid->value]);
        $this->fee($this->schoolB, ['amount' => '2000000', 'amount_paid' => '500000', 'status' => StudentFeeStatus::Partial->value]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $rows = collect(Livewire::test(LaporanKeuanganCabang::class)->get('rows'))->keyBy('school_name');

        $this->assertSame('1000000.00', $rows['Cabang Alpha']['total_billed']);
        $this->assertSame('100.00', $rows['Cabang Alpha']['paid_percentage']);
        $this->assertSame('2000000.00', $rows['Cabang Beta']['total_billed']);
        $this->assertSame('0.00', $rows['Cabang Beta']['paid_percentage']);
    }

    public function test_the_page_renders_the_documented_columns(): void
    {
        $this->fee($this->schoolA);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuanganCabang::class)
            ->assertSee('Cabang')
            ->assertSee('Total Tagihan')
            ->assertSee('Total Terkumpul')
            ->assertSee('Persentase Lunas')
            ->assertSee('Cabang Alpha');
    }

    /**
     * KAS-02 tidak digabungkan ke sini: tidak ada saldo kas, pengeluaran,
     * maupun tren cabang.
     */
    public function test_the_page_carries_no_kas_02_metrics(): void
    {
        $this->fee($this->schoolA);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuanganCabang::class)
            ->assertDontSee('Saldo Kas')
            ->assertDontSee('Pengeluaran Bulan Ini')
            ->assertDontSee('Tren 6 Bulan Terakhir');
    }

    public function test_totals_across_branches_are_summed_from_the_rows(): void
    {
        $this->fee($this->schoolA, ['amount' => '1000000', 'amount_paid' => '400000']);
        $this->fee($this->schoolB, ['amount' => '2000000', 'amount_paid' => '600000']);

        $this->actingAs(User::factory()->superAdmin()->create());

        $totals = Livewire::test(LaporanKeuanganCabang::class)->instance()->totals();

        $this->assertSame('3000000.00', $totals['total_billed']);
        $this->assertSame('1000000.00', $totals['total_collected']);
    }

    // --------------------------------------------------------------- filter

    public function test_the_month_filter_narrows_the_rows(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000', 'period' => '2026-07']);
        $this->fee($this->schoolA, ['amount' => '200000', 'period' => '2026-08']);

        $this->actingAs(User::factory()->superAdmin()->create());

        $page = Livewire::test(LaporanKeuanganCabang::class);

        $this->assertSame('300000.00', collect($page->get('rows'))->firstWhere('school_name', 'Cabang Alpha')['total_billed']);

        $page->set('data.period', '2026-07');

        $this->assertSame('100000.00', collect($page->get('rows'))->firstWhere('school_name', 'Cabang Alpha')['total_billed']);
    }

    public function test_the_academic_year_filter_narrows_the_rows(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);

        $this->fee($this->schoolA, ['amount' => '100000', 'academic_year_id' => $year->id]);
        $this->fee($this->schoolA, ['amount' => '900000', 'academic_year_id' => null]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $page = Livewire::test(LaporanKeuanganCabang::class)
            ->set('data.academic_year', '2026/2027 Ganjil');

        $this->assertSame('100000.00', collect($page->get('rows'))->firstWhere('school_name', 'Cabang Alpha')['total_billed']);
    }

    public function test_both_filters_combine_on_the_page(): void
    {
        $year = AcademicYear::factory()->create(['school_id' => $this->schoolA->id, 'name' => '2026/2027 Ganjil']);

        $this->fee($this->schoolA, ['amount' => '100000', 'academic_year_id' => $year->id, 'period' => '2026-08']);
        $this->fee($this->schoolA, ['amount' => '200000', 'academic_year_id' => $year->id, 'period' => '2026-09']);

        $this->actingAs(User::factory()->superAdmin()->create());

        $page = Livewire::test(LaporanKeuanganCabang::class)
            ->set('data.academic_year', '2026/2027 Ganjil')
            ->set('data.period', '2026-08');

        $this->assertSame('100000.00', collect($page->get('rows'))->firstWhere('school_name', 'Cabang Alpha')['total_billed']);
    }

    public function test_an_invalid_month_empties_the_rows_instead_of_crashing(): void
    {
        $this->fee($this->schoolA);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuanganCabang::class)
            ->set('data.period', '2026-13')
            ->assertSuccessful()
            ->assertSet('rows', []);
    }

    public function test_the_filters_default_to_showing_everything(): void
    {
        $this->fee($this->schoolA, ['amount' => '100000', 'period' => '2026-07']);
        $this->fee($this->schoolA, ['amount' => '200000', 'period' => '2026-09']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuanganCabang::class)
            ->assertSet('data.academic_year', null)
            ->assertSet('data.period', null);
    }

    // ------------------------------------------------------------- cakupan

    public function test_an_inactive_branch_with_history_is_marked_but_kept(): void
    {
        $closed = School::factory()->create(['name' => 'Cabang Tutup', 'is_active' => false]);

        $this->fee($closed, ['amount' => '750000']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(LaporanKeuanganCabang::class)
            ->assertSee('Cabang Tutup')
            ->assertSee('Nonaktif');
    }

    /**
     * Kepemilikan cabang tidak pernah dibaca dari payload: Super Admin memang
     * melihat seluruhnya, dan peran lain tidak dapat masuk sama sekali.
     */
    public function test_no_school_id_field_exists_to_be_injected(): void
    {
        $this->fee($this->schoolA);

        $this->actingAs(User::factory()->superAdmin()->create());

        $page = Livewire::test(LaporanKeuanganCabang::class)
            ->set('data.school_id', $this->schoolB->id);

        // Field itu tidak ada di form; ringkasannya tetap memuat kedua cabang.
        $this->assertCount(2, $page->get('rows'));
    }
}
