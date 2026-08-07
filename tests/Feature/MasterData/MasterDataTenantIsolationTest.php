<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-02 / Arsitektur 3.2 — isolasi data seluruh entitas Master Data.
 * NFR: "Data isolation 100% — tidak ada kebocoran data antar tenant."
 */
class MasterDataTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_every_master_data_entity_is_scoped_to_the_current_school(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        foreach ([$schoolA, $schoolB] as $school) {
            $year = AcademicYear::factory()->create(['school_id' => $school->id]);
            Subject::factory()->create(['school_id' => $school->id]);
            Student::factory()->create(['school_id' => $school->id]);
            SchoolClass::factory()->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $admin = User::factory()->forSchool($schoolA)->withRole(RoleName::SchoolAdmin)->create();
        $this->actingAs($admin);

        $this->assertSame(1, AcademicYear::query()->count());
        $this->assertSame(1, Subject::query()->count());
        $this->assertSame(1, Student::query()->count());
        $this->assertSame(1, SchoolClass::query()->count());

        $this->assertSame([$schoolA->id], Student::query()->pluck('school_id')->unique()->values()->all());
    }

    public function test_super_admin_sees_master_data_of_all_schools(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        Student::factory()->create(['school_id' => $schoolA->id]);
        Student::factory()->create(['school_id' => $schoolB->id]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, Student::query()->count());
    }

    public function test_school_id_is_filled_automatically_on_create(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();

        $this->actingAs($admin);

        $subject = Subject::create(['name' => 'Fikih', 'code' => 'FIK', 'is_active' => true]);

        $this->assertSame($school->id, $subject->school_id);
    }

    public function test_student_of_another_school_cannot_be_managed(): void
    {
        $schoolA = School::factory()->create();
        $schoolB = School::factory()->create();

        $admin = User::factory()->forSchool($schoolA)->withRole(RoleName::SchoolAdmin)->create();
        $own = Student::factory()->create(['school_id' => $schoolA->id]);
        $foreign = Student::factory()->create(['school_id' => $schoolB->id]);

        $this->assertTrue($admin->can('update', $own));
        $this->assertFalse($admin->can('update', $foreign));
        $this->assertFalse($admin->can('view', $foreign));
    }
}
