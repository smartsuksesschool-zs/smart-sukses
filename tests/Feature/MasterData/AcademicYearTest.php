<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ERD 2.2 & API 4.6 — tahun ajaran aktif.
 */
class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_activating_a_year_deactivates_the_others_in_the_same_school(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $first = AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);
        $second = AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => false]);

        $second->activate();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_activating_does_not_touch_another_school(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $foreign = AcademicYear::factory()->create(['school_id' => $schoolB->id, 'is_active' => true]);
        $own = AcademicYear::factory()->create(['school_id' => $schoolA->id, 'is_active' => false]);

        $admin = User::factory()->forSchool($schoolA)->withRole(RoleName::SchoolAdmin)->create();
        $this->actingAs($admin);

        $own->activate();

        // Tahun ajaran cabang lain harus tetap aktif (isolasi tenant).
        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertTrue($own->fresh()->is_active);
    }

    public function test_current_returns_the_active_year_of_the_tenant(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => false]);
        $active = AcademicYear::factory()->create(['school_id' => $school->id, 'is_active' => true]);

        $this->actingAs($admin);

        $this->assertSame($active->id, AcademicYear::current()?->id);
    }
}
