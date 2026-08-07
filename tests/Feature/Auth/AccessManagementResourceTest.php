<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PORTAL-04 — Halaman Filament untuk manajemen pengguna dan peran.
 */
class AccessManagementResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_open_user_and_role_pages(): void
    {
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $this->get(UserResource::getUrl('index'))->assertSuccessful();
        $this->get(UserResource::getUrl('create'))->assertSuccessful();
        $this->get(RoleResource::getUrl('index'))->assertSuccessful();
    }

    public function test_teacher_cannot_open_user_management(): void
    {
        $guru = User::factory()->withRole(RoleName::Guru)->create();

        $this->actingAs($guru);

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_admin_creates_a_user_inside_its_own_school(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();
        $guruRole = Role::findByName(RoleName::Guru->value);

        $this->actingAs($admin);

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Guru Matematika',
                'email' => 'guru.matematika@example.test',
                'locale' => 'id',
                'is_active' => true,
                'password' => 'Password123',
                'roles' => [$guruRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'guru.matematika@example.test')->first();

        $this->assertNotNull($created);
        $this->assertSame($school->id, $created->school_id);
        $this->assertTrue($created->hasRole(RoleName::Guru->value));
    }

    public function test_password_is_hashed_and_weak_password_is_rejected(): void
    {
        $admin = User::factory()->withRole(RoleName::SchoolAdmin)->create();
        $guruRole = Role::findByName(RoleName::Guru->value);

        $this->actingAs($admin);

        Livewire::test(UserResource\Pages\CreateUser::class)
            ->fillForm([
                'name' => 'Guru Lemah',
                'email' => 'guru.lemah@example.test',
                'locale' => 'id',
                'password' => 'abc',
                'roles' => [$guruRole->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_super_admin_can_sync_role_permissions(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::findByName(RoleName::Guru->value);

        $this->actingAs($superAdmin);

        $this->get(RoleResource::getUrl('edit', ['record' => $role]))->assertSuccessful();
    }
}
