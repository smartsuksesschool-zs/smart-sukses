<?php

namespace Tests\Feature\Auth;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PRD 1.1 — Peran & Matriks Akses.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_all_documented_roles_and_permissions_exist(): void
    {
        foreach (RoleName::cases() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role->value, 'guard_name' => 'web']);
        }

        $this->assertSame(count(PermissionName::cases()), Permission::count());
    }

    public function test_super_admin_bypasses_every_gate(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue($superAdmin->can(PermissionName::TenantManage->value));
        $this->assertTrue($superAdmin->can('ability.that.does.not.exist'));
    }

    public function test_school_admin_has_no_tenant_management_access(): void
    {
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();

        $this->assertTrue($admin->can(PermissionName::UserManage->value));
        $this->assertFalse($admin->can(PermissionName::TenantManage->value));
        $this->assertFalse($admin->can(PermissionName::TenantView->value));
    }

    public function test_kepala_sekolah_only_reads_academic_and_finance_modules(): void
    {
        $kepala = User::factory()->withRole(RoleName::KepalaSekolah)->create();

        $this->assertTrue($kepala->can(PermissionName::GradeView->value));
        $this->assertFalse($kepala->can(PermissionName::GradeManage->value));

        $this->assertTrue($kepala->can(PermissionName::FinancialReportView->value));
        $this->assertFalse($kepala->can(PermissionName::PaymentView->value));

        // Notifikasi adalah satu-satunya modul dengan akses penuh (PRD 1.1.2).
        $this->assertTrue($kepala->can(PermissionName::NotificationManage->value));
    }

    public function test_guru_can_manage_grades_but_not_report_cards(): void
    {
        $guru = User::factory()->withRole(RoleName::Guru)->create();
        $wali = User::factory()->withRole(RoleName::WaliKelas)->create();

        $this->assertTrue($guru->can(PermissionName::GradeManage->value));
        $this->assertFalse($guru->can(PermissionName::ReportCardManage->value));

        // Wali Kelas = semua akses guru + kelola rapor.
        $this->assertTrue($wali->can(PermissionName::GradeManage->value));
        $this->assertTrue($wali->can(PermissionName::ReportCardManage->value));
    }

    public function test_bendahara_owns_finance_modules_only(): void
    {
        $bendahara = User::factory()->withRole(RoleName::Bendahara)->create();

        $this->assertTrue($bendahara->can(PermissionName::FeeManage->value));
        $this->assertTrue($bendahara->can(PermissionName::PaymentManage->value));
        $this->assertTrue($bendahara->can(PermissionName::AccountingManage->value));
        $this->assertTrue($bendahara->can(PermissionName::StudentView->value));
        $this->assertFalse($bendahara->can(PermissionName::StudentManage->value));
        $this->assertFalse($bendahara->can(PermissionName::GradeView->value));
    }

    public function test_portal_roles_are_limited_to_their_own_portal(): void
    {
        $siswa = User::factory()->withRole(RoleName::Siswa)->create();
        $ortu = User::factory()->withRole(RoleName::OrangTua)->create();

        $this->assertTrue($siswa->can(PermissionName::StudentPortalManage->value));
        $this->assertFalse($siswa->can(PermissionName::ParentPortalView->value));

        $this->assertTrue($ortu->can(PermissionName::ParentPortalManage->value));
        $this->assertTrue($ortu->can(PermissionName::FeeView->value));
        $this->assertFalse($ortu->can(PermissionName::FeeManage->value));
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = Role::findByName(RoleName::Bendahara->value)->permissions->count();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(count(RoleName::cases()), Role::count());
        $this->assertSame(count(PermissionName::cases()), Permission::count());
        $this->assertSame($before, Role::findByName(RoleName::Bendahara->value)->permissions()->count());
    }

    public function test_only_super_admin_may_edit_role_definitions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();
        $role = Role::findByName(RoleName::Guru->value);

        $this->assertTrue($superAdmin->can('update', $role));
        $this->assertTrue($admin->can('view', $role));
        $this->assertFalse($admin->can('update', $role));
    }
}
