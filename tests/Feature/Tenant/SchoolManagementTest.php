<?php

namespace Tests\Feature\Tenant;

use App\Enums\RoleName;
use App\Filament\Resources\SchoolResource;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PRD 1.1.2 baris "Manajemen Tenant/Cabang" — hanya SUPER_ADMIN (✅), seluruh
 * peran lain ❌. API 4.3 — GET/POST /admin/schools, PUT /admin/schools/{id},
 * PATCH /admin/schools/{id}/toggle.
 */
class SchoolManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_super_admin_sees_every_branch(): void
    {
        $schools = School::factory()->count(3)->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\ListSchools::class)
            ->assertCanSeeTableRecords($schools);
    }

    public function test_super_admin_creates_a_branch(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\CreateSchool::class)
            ->fillForm([
                'name' => 'Smart Sukses School Madani',
                'code' => 'MADANI',
                'slug' => 'madani',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('schools', [
            'code' => 'MADANI',
            'slug' => 'madani',
            'primary_color' => '#123456',
            'is_active' => true,
        ]);
    }

    public function test_super_admin_edits_a_branch(): void
    {
        $school = School::factory()->create(['name' => 'Nama Lama']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\EditSchool::class, ['record' => $school->getKey()])
            ->fillForm(['name' => 'Nama Baru', 'head_name' => 'Bu Kepala'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Baru', $school->fresh()->name);
        $this->assertSame('Bu Kepala', $school->fresh()->head_name);
    }

    public function test_super_admin_opens_branch_detail(): void
    {
        $school = School::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(SchoolResource::getUrl('view', ['record' => $school]))->assertSuccessful();
    }

    public function test_super_admin_deactivates_and_reactivates_a_branch(): void
    {
        // API 4.3 — PATCH /admin/schools/{id}/toggle. Cabangnya tidak dihapus:
        // seluruh data akademik dan keuangan menggantung padanya.
        $school = School::factory()->create(['is_active' => true]);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\ListSchools::class)
            ->callTableAction('toggleActive', $school);

        $this->assertFalse($school->fresh()->is_active);
        $this->assertDatabaseHas('schools', ['id' => $school->getKey()]);

        Livewire::test(SchoolResource\Pages\ListSchools::class)
            ->callTableAction('toggleActive', $school);

        $this->assertTrue($school->fresh()->is_active);
    }

    public function test_no_delete_path_is_exposed_for_a_branch(): void
    {
        // API 4.3 tidak mengenal DELETE /admin/schools/{id} — hanya toggle.
        // Menghapus tenant akan memutus seluruh data akademik, keuangan, dan
        // rapor yang menggantung padanya.
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->assertFalse($admin->can('delete', $school));

        $this->actingAs(User::factory()->superAdmin()->create());

        // Super Admin sendiri tetap diloloskan Gate::before (Arsitektur 3.2.2),
        // sehingga yang benar-benar menutup jalur hapus adalah tidak adanya aksi
        // itu di mana pun — bukan policy-nya. Justru itu yang diperiksa di sini.
        Livewire::test(SchoolResource\Pages\ListSchools::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(SchoolResource\Pages\EditSchool::class, ['record' => $school->getKey()])
            ->assertActionDoesNotExist('delete');
    }

    public function test_school_admin_cannot_reach_tenant_management(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        // Matriks 1.1.2: "Manajemen Tenant/Cabang" hanya ✅ untuk SUPER_ADMIN.
        $this->assertFalse($admin->can('viewAny', School::class));
        $this->assertFalse($admin->can('create', School::class));
        $this->assertFalse($admin->can('update', $school));
        $this->assertFalse(SchoolResource::canAccess());

        $this->get(SchoolResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_navigation_item_is_hidden_from_school_admin(): void
    {
        // Filament menyembunyikan item navigasi lewat canAccess(); tidak ada
        // menu "Cabang Sekolah" bagi peran yang tidak punya izin tenant.
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);
        $this->assertFalse(SchoolResource::canAccess());

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->assertTrue(SchoolResource::canAccess());
    }

    public function test_roles_without_tenant_permission_are_blocked(): void
    {
        $school = School::factory()->create();

        foreach ([RoleName::KepalaSekolah, RoleName::Guru, RoleName::Bendahara, RoleName::Siswa] as $role) {
            $user = User::factory()->forSchool($school)->withRole($role)->create();

            $this->assertFalse(
                $user->can('viewAny', School::class),
                "{$role->value} seharusnya tidak dapat melihat daftar cabang.",
            );
        }
    }

    public function test_branch_query_is_isolated_for_school_level_users(): void
    {
        // Pagar lapis kedua: `schools` tidak punya kolom school_id sehingga tidak
        // tersentuh SchoolScope. Bila suatu saat peran School Level diberi
        // tenant.view, ia tetap hanya melihat cabangnya sendiri.
        $own = School::factory()->create();
        $other = School::factory()->create();

        $admin = User::factory()->forSchool($own)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $visible = SchoolResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([$own->getKey()], $visible);
        $this->assertNotContains($other->getKey(), $visible);
    }

    public function test_code_and_slug_must_be_unique(): void
    {
        School::factory()->create(['code' => 'PUSAT', 'slug' => 'pusat']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\CreateSchool::class)
            ->fillForm([
                'name' => 'Cabang Kembar',
                'code' => 'PUSAT',
                'slug' => 'pusat',
                'primary_color' => '#1B3A6B',
                'secondary_color' => '#E07020',
            ])
            ->call('create')
            ->assertHasFormErrors(['code', 'slug']);

        $this->assertSame(1, School::query()->count());
    }

    public function test_editing_a_branch_does_not_collide_with_its_own_code(): void
    {
        $school = School::factory()->create(['code' => 'PUSAT', 'slug' => 'pusat']);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(SchoolResource\Pages\EditSchool::class, ['record' => $school->getKey()])
            ->fillForm(['name' => 'Pusat Diperbarui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pusat Diperbarui', $school->fresh()->name);
    }

    public function test_a_smuggled_record_id_cannot_expose_another_branch(): void
    {
        // Payload jahat: Admin Sekolah menebak URL edit cabang lain. Yang
        // menahannya adalah policy, bukan sekadar tidak adanya tautan di UI.
        $own = School::factory()->create();
        $other = School::factory()->create(['name' => 'Cabang Rahasia']);

        $admin = User::factory()->forSchool($own)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        // 404, bukan 403: penyaringan getEloquentQuery() membuat cabang lain
        // tidak pernah ter-resolve sebagai record: keberadaannya pun tidak
        // terkonfirmasi. Ini justru lebih rapat daripada sekadar menolak akses.
        $this->get(SchoolResource::getUrl('edit', ['record' => $other]))->assertNotFound();
        $this->get(SchoolResource::getUrl('view', ['record' => $other]))->assertNotFound();

        $this->assertFalse($admin->can('update', $other));
        $this->assertFalse($admin->can('view', $other));

        $this->assertDatabaseHas('schools', ['id' => $other->getKey(), 'name' => 'Cabang Rahasia']);
    }

    public function test_an_inactive_branch_disappears_from_the_public_ppdb_list(): void
    {
        // Perilaku "nonaktif" yang memang sudah didefinisikan requirement
        // (butir 14 — "cabang yang membuka PPDB" dipetakan ke schools.is_active).
        $active = School::factory()->create(['name' => 'Cabang Menerima']);
        $inactive = School::factory()->inactive()->create(['name' => 'Cabang Tutup']);

        $this->get(route('ppdb.schools'))
            ->assertSuccessful()
            ->assertSee($active->name)
            ->assertDontSee($inactive->name);
    }
}
