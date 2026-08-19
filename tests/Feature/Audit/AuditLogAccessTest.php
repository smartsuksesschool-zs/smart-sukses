<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Filament\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kewenangan membaca jejak audit. Matriks PRD 1.1.2 tidak memiliki baris untuk
 * Audit Log dan tidak ada izin `audit.*`; kewenangannya karena itu menumpang
 * izin paling ketat yang sudah ada — `tenant.view`, milik SUPER_ADMIN saja
 * (butir 45).
 */
class AuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function logFor(School $school): AuditLog
    {
        return AuditLog::query()->create([
            'school_id' => $school->getKey(),
            'user_id' => null,
            'action' => AuditAction::Created->value,
            'auditable_type' => Student::class,
            'auditable_id' => 1,
            'ip_address' => '198.51.100.4',
        ]);
    }

    public function test_super_admin_can_open_the_audit_log(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(AuditLogResource::getUrl('index'))->assertSuccessful();
        $this->assertTrue(AuditLogResource::canAccess());
    }

    public function test_super_admin_sees_records_across_every_branch(): void
    {
        $madani = School::factory()->create();
        $cinangka = School::factory()->create();

        $first = $this->logFor($madani);
        $second = $this->logFor($cinangka);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(AuditLogResource\Pages\ListAuditLogs::class)
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_platform_rows_with_a_null_school_remain_visible(): void
    {
        // Aksi platform (mis. Super Admin membuat cabang) menyimpan school_id
        // NULL; SchoolScope tidak boleh membuatnya hilang bagi Super Admin.
        $platformLog = AuditLog::query()->create([
            'school_id' => null,
            'user_id' => null,
            'action' => AuditAction::Created->value,
            'auditable_type' => School::class,
            'auditable_id' => 1,
            'ip_address' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(AuditLogResource\Pages\ListAuditLogs::class)
            ->assertCanSeeTableRecords([$platformLog]);
    }

    public function test_school_admin_cannot_open_the_audit_log(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $this->assertFalse($admin->can('viewAny', AuditLog::class));
        $this->assertFalse(AuditLogResource::canAccess());
        $this->get(AuditLogResource::getUrl('index'))->assertForbidden();
    }

    public function test_other_roles_cannot_open_the_audit_log(): void
    {
        $school = School::factory()->create();

        foreach ([RoleName::KepalaSekolah, RoleName::Guru, RoleName::Bendahara, RoleName::Siswa, RoleName::OrangTua] as $role) {
            $user = User::factory()->forSchool($school)->withRole($role)->create();

            $this->assertFalse(
                $user->can('viewAny', AuditLog::class),
                "{$role->value} seharusnya tidak dapat membaca audit log.",
            );
        }
    }

    public function test_the_audit_log_is_read_only_in_the_panel(): void
    {
        $school = School::factory()->create();
        $log = $this->logFor($school);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin);

        // Tidak ada resource `create`, dan tidak ada aksi sunting/hapus di mana
        // pun — termasuk bagi Super Admin, yang Gate::before-nya meloloskan
        // segalanya. Yang menutup jalurnya adalah ketiadaan aksinya.
        $this->assertFalse(AuditLogResource::canCreate());

        Livewire::test(AuditLogResource\Pages\ListAuditLogs::class)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(AuditLogResource\Pages\ViewAuditLog::class, ['record' => $log->getKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');

        $this->assertArrayNotHasKey('create', AuditLogResource::getPages());
        $this->assertArrayNotHasKey('edit', AuditLogResource::getPages());
    }

    public function test_a_school_admin_cannot_read_another_branch_through_the_model(): void
    {
        // Lapis kedua: seandainya izin `tenant.view` kelak diberikan ke peran
        // School Level, SchoolScope tetap menyaring jejak cabang lain.
        $own = School::factory()->create();
        $other = School::factory()->create();

        $ownLog = $this->logFor($own);
        $otherLog = $this->logFor($other);

        $admin = User::factory()->forSchool($own)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $visible = AuditLog::query()->pluck('id')->all();

        $this->assertContains($ownLog->getKey(), $visible);
        $this->assertNotContains($otherLog->getKey(), $visible);
    }

    public function test_the_detail_page_shows_the_recorded_fields(): void
    {
        $school = School::factory()->create(['name' => 'Cabang Jejak']);
        $log = $this->logFor($school);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(AuditLogResource::getUrl('view', ['record' => $log]))
            ->assertSuccessful()
            ->assertSee('Cabang Jejak')
            ->assertSee('198.51.100.4')
            ->assertSee('students');
    }
}
