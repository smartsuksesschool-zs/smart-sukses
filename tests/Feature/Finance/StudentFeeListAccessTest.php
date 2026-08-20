<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Filament\Resources\StudentFeeResource;
use App\Filament\Resources\StudentFeeResource\Pages\ListStudentFees;
use App\Filament\Resources\StudentFeeResource\Pages\ViewStudentFee;
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
 * PRD 1.1.2 — modul "Tagihan SPP" (SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕,
 * GURU/WALI ❌, BENDAHARA ✅) dan Arsitektur 3.2 (isolasi per cabang).
 */
class StudentFeeListAccessTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected StudentFee $feeA;

    protected StudentFee $feeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolA = School::factory()->create();
        $this->schoolB = School::factory()->create();

        $this->feeA = $this->makeFee($this->schoolA, 'Siswa A');
        $this->feeB = $this->makeFee($this->schoolB, 'Siswa B');
    }

    protected function makeFee(School $school, string $studentName): StudentFee
    {
        $student = Student::factory()->create([
            'school_id' => $school->id,
            'full_name' => $studentName,
        ]);

        return StudentFee::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'fee_type_id' => FeeType::factory()->create(['school_id' => $school->id])->id,
        ]);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesThatMaySeeTheList(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'admin sekolah' => [RoleName::SchoolAdmin],
            // Matriks: "Tagihan SPP" KEPALA = ⭕ — melihat, tidak mengelola.
            'kepala sekolah' => [RoleName::KepalaSekolah],
        ];
    }

    #[DataProvider('rolesThatMaySeeTheList')]
    public function test_authorized_roles_see_the_student_fee_list(RoleName $role): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

        $this->get(StudentFeeResource::getUrl('index'))->assertSuccessful();

        Livewire::test(ListStudentFees::class)->assertCanSeeTableRecords([$this->feeA]);
    }

    public function test_super_admin_sees_student_fees_across_branches(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListStudentFees::class)
            ->assertCanSeeTableRecords([$this->feeA, $this->feeB]);
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function rolesWithoutFeeAccess(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('rolesWithoutFeeAccess')]
    public function test_roles_outside_the_module_cannot_open_the_list(RoleName $role): void
    {
        $user = User::factory()->forSchool($this->schoolA)->withRole($role)->create();

        $this->actingAs($user);

        $this->assertFalse(StudentFeeResource::canAccess());
        $this->assertFalse($user->can('viewAny', StudentFee::class));
        $this->assertFalse($user->can('view', $this->feeA));

        $this->get(StudentFeeResource::getUrl('index'))->assertForbidden();
        $this->get(StudentFeeResource::getUrl('view', ['record' => $this->feeA]))->assertForbidden();
    }

    /**
     * SISWA ❌ pada matriks; ORTU ⭕ tetapi dilayani portal terpisah yang belum
     * ada (SPP-04) — keduanya tidak masuk panel admin sama sekali.
     */
    public function test_student_and_parent_never_reach_the_student_fee_pages(): void
    {
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $this->actingAs(User::factory()->forSchool($this->schoolA)->withRole($role)->create());

            $this->get(StudentFeeResource::getUrl('index'))->assertForbidden();
        }
    }

    public function test_tenant_only_sees_its_own_student_fees(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(ListStudentFees::class)
            ->assertCanSeeTableRecords([$this->feeA])
            ->assertCanNotSeeTableRecords([$this->feeB]);
    }

    /**
     * Menebak id tagihan cabang lain tidak boleh membuka detailnya.
     */
    public function test_direct_view_url_of_a_foreign_student_fee_fails(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        $this->get(StudentFeeResource::getUrl('view', ['record' => $this->feeB]))
            ->assertNotFound();
    }

    public function test_view_page_shows_own_student_fee(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(ViewStudentFee::class, ['record' => $this->feeA->getKey()])
            ->assertSuccessful();
    }

    /**
     * SPP-02 menerbitkan tagihan secara massal; tidak ada user story yang
     * meminta tagihan dibuat, diedit, atau dihapus satu per satu.
     */
    public function test_student_fees_have_no_create_edit_or_delete_route(): void
    {
        $this->assertFalse(StudentFeeResource::canCreate());

        $this->assertSame(
            ['index', 'view'],
            array_keys(StudentFeeResource::getPages()),
        );
    }

    public function test_list_table_exposes_no_bulk_or_delete_action(): void
    {
        $this->actingAs(User::factory()->forSchool($this->schoolA)
            ->withRole(RoleName::Bendahara)->create());

        Livewire::test(ListStudentFees::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
