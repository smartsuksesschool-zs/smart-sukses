<?php

namespace Tests\Feature\Finance;

use App\Enums\RoleName;
use App\Filament\Resources\FeeTypeResource;
use App\Models\FeeType;
use App\Models\School;
use App\Models\User;
use App\Policies\FeeTypePolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PRD 1.1.2 — modul "Tagihan SPP":
 * SUPER_ADMIN ✅, SCHOOL_ADMIN ✅, KEPALA ⭕, GURU/WALI ❌, BENDAHARA ✅,
 * SISWA ❌, ORTU ⭕ (lewat portal, bukan panel admin).
 */
class FeeTypeRbacTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();
    }

    protected function user(RoleName $role): User
    {
        return User::factory()->forSchool($this->school)->withRole($role)->create();
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function managingRoles(): array
    {
        return [
            'bendahara' => [RoleName::Bendahara],
            'admin sekolah' => [RoleName::SchoolAdmin],
        ];
    }

    #[DataProvider('managingRoles')]
    public function test_authorized_roles_can_manage_fee_types(RoleName $role): void
    {
        $user = $this->user($role);
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($user);

        $this->get(FeeTypeResource::getUrl('index'))->assertSuccessful();
        $this->get(FeeTypeResource::getUrl('create'))->assertSuccessful();
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $feeType]))->assertSuccessful();

        $this->assertTrue($user->can('create', FeeType::class));
        $this->assertTrue($user->can('update', $feeType));
    }

    /**
     * KEPALA ⭕ — boleh membaca, tidak boleh mengubah.
     */
    public function test_kepala_sekolah_has_read_only_access(): void
    {
        $kepala = $this->user(RoleName::KepalaSekolah);
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($kepala);

        $this->get(FeeTypeResource::getUrl('index'))->assertSuccessful();
        $this->get(FeeTypeResource::getUrl('create'))->assertForbidden();
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $feeType]))->assertForbidden();

        $this->assertTrue($kepala->can('view', $feeType));
        $this->assertFalse($kepala->can('create', FeeType::class));
        $this->assertFalse($kepala->can('update', $feeType));
    }

    /**
     * @return array<string, array{RoleName}>
     */
    public static function deniedRoles(): array
    {
        return [
            'guru' => [RoleName::Guru],
            'wali kelas' => [RoleName::WaliKelas],
        ];
    }

    #[DataProvider('deniedRoles')]
    public function test_roles_without_fee_access_are_rejected(RoleName $role): void
    {
        $user = $this->user($role);
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs($user);

        $this->get(FeeTypeResource::getUrl('index'))->assertForbidden();
        $this->get(FeeTypeResource::getUrl('create'))->assertForbidden();
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $feeType]))->assertForbidden();

        $this->assertFalse($user->can('viewAny', FeeType::class));
        $this->assertFalse($user->can('view', $feeType));
        $this->assertFalse($user->can('create', FeeType::class));
        $this->assertFalse($user->can('update', $feeType));
    }

    /**
     * SISWA ❌ pada matriks; ORTU ⭕ tetapi dilayani portal terpisah
     * (RoleName::canAccessAdminPanel), sehingga keduanya tidak masuk panel.
     */
    public function test_student_and_parent_never_reach_the_admin_panel(): void
    {
        foreach ([RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = $this->user($role);

            $this->actingAs($user);

            $this->get(FeeTypeResource::getUrl('index'))->assertForbidden();
        }

        $siswa = $this->user(RoleName::Siswa);
        $this->assertFalse($siswa->can('viewAny', FeeType::class));
    }

    public function test_super_admin_reaches_every_page(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(FeeTypeResource::getUrl('index'))->assertSuccessful();
        $this->get(FeeTypeResource::getUrl('create'))->assertSuccessful();
        $this->get(FeeTypeResource::getUrl('edit', ['record' => $feeType]))->assertSuccessful();
    }

    /**
     * Jenis tagihan tidak pernah dihapus, sekalipun oleh Super Admin —
     * Gate::before meloloskan mereka, jadi policy diuji langsung.
     */
    public function test_nobody_may_delete_a_fee_type(): void
    {
        $feeType = FeeType::factory()->create(['school_id' => $this->school->id]);

        $this->assertFalse(
            app(FeeTypePolicy::class)->delete(
                User::factory()->superAdmin()->create(),
                $feeType,
            ),
        );

        $this->assertFalse($this->user(RoleName::SchoolAdmin)->can('delete', $feeType));
    }
}
