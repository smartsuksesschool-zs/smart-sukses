<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-02 / Arsitektur 3.2 — Isolasi data per school_id.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_school_level_user_only_sees_users_of_its_own_school(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $admin = User::factory()->forSchool($schoolA)->withRole(RoleName::SchoolAdmin)->create();
        User::factory()->forSchool($schoolA)->withRole(RoleName::Guru)->create();
        User::factory()->forSchool($schoolB)->withRole(RoleName::Guru)->create();

        $this->actingAs($admin);

        $visible = User::query()->pluck('school_id')->unique();

        $this->assertSame([$schoolA->id], $visible->values()->all());
        $this->assertSame(2, User::query()->count());
    }

    public function test_super_admin_sees_data_across_all_schools(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->forSchool($schoolA)->withRole(RoleName::Guru)->create();
        User::factory()->forSchool($schoolB)->withRole(RoleName::Guru)->create();

        $this->actingAs($superAdmin);

        $this->assertSame(3, User::query()->count());
    }

    public function test_school_id_is_filled_automatically_for_new_records(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $created = User::create([
            'name' => 'Guru Baru',
            'email' => 'guru.baru@example.test',
            'password' => 'Password123',
        ]);

        $this->assertSame($school->id, $created->school_id);
    }

    public function test_user_of_another_school_cannot_be_managed(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $admin = User::factory()->forSchool($schoolA)->withRole(RoleName::SchoolAdmin)->create();
        $foreignUser = User::factory()->forSchool($schoolB)->withRole(RoleName::Guru)->create();
        $ownUser = User::factory()->forSchool($schoolA)->withRole(RoleName::Guru)->create();

        $this->assertTrue($admin->can('update', $ownUser));
        $this->assertFalse($admin->can('update', $foreignUser));
        $this->assertFalse($admin->can('view', $foreignUser));
    }

    public function test_login_lookup_by_email_is_not_scoped(): void
    {
        $school = School::factory()->create();
        User::factory()->forSchool($school)->withRole(RoleName::Guru)->create([
            'email' => 'guru@example.test',
        ]);

        // Tanpa pengguna terautentikasi (alur login), lookup harus lintas cabang.
        $this->assertNotNull(User::where('email', 'guru@example.test')->first());
    }
}
