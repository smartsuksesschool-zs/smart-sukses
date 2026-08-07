<?php

namespace Tests\Feature\MasterData;

use App\Enums\RoleName;
use App\Filament\Resources\AcademicYearResource;
use App\Filament\Resources\ScheduleResource;
use App\Filament\Resources\SchoolClassResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\SubjectResource;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD 1.1.2 — matriks izin modul "Data Siswa (SIS)" dan "Kelas & Jadwal"
 * diterapkan pada seluruh resource Master Data.
 */
class MasterDataRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_school_admin_can_open_every_master_data_page(): void
    {
        $this->actingAs(User::factory()->withRole(RoleName::SchoolAdmin)->create());

        $this->get(AcademicYearResource::getUrl('index'))->assertSuccessful();
        $this->get(SubjectResource::getUrl('index'))->assertSuccessful();
        $this->get(StudentResource::getUrl('index'))->assertSuccessful();
        $this->get(SchoolClassResource::getUrl('index'))->assertSuccessful();
        $this->get(ScheduleResource::getUrl('index'))->assertSuccessful();
    }

    public function test_teacher_may_read_but_not_create(): void
    {
        $school = School::factory()->create();
        $guru = User::factory()->forSchool($school)->withRole(RoleName::Guru)->create();
        $student = Student::factory()->create(['school_id' => $school->id]);

        $this->actingAs($guru);

        // Data Siswa ⭕ dan Kelas & Jadwal ⭕ untuk GURU.
        $this->get(StudentResource::getUrl('index'))->assertSuccessful();
        $this->get(ScheduleResource::getUrl('index'))->assertSuccessful();

        $this->assertTrue($guru->can('view', $student));
        $this->assertFalse($guru->can('update', $student));
        $this->assertFalse($guru->can('create', Student::class));
        $this->get(StudentResource::getUrl('create'))->assertForbidden();
    }

    public function test_bendahara_can_read_students_but_not_classes(): void
    {
        $bendahara = User::factory()->withRole(RoleName::Bendahara)->create();

        $this->actingAs($bendahara);

        // Matriks: BENDAHARA → Data Siswa ⭕, Kelas & Jadwal ❌.
        $this->get(StudentResource::getUrl('index'))->assertSuccessful();
        $this->get(SchoolClassResource::getUrl('index'))->assertForbidden();
        $this->get(ScheduleResource::getUrl('index'))->assertForbidden();
    }

    public function test_kepala_sekolah_has_read_only_access(): void
    {
        $school = School::factory()->create();
        $kepala = User::factory()->forSchool($school)->withRole(RoleName::KepalaSekolah)->create();
        $student = Student::factory()->create(['school_id' => $school->id]);

        $this->actingAs($kepala);

        $this->get(StudentResource::getUrl('index'))->assertSuccessful();
        $this->assertTrue($kepala->can('view', $student));
        $this->assertFalse($kepala->can('update', $student));
    }

    public function test_students_are_never_hard_deleted(): void
    {
        // SIS-02 poin 2: siswa dinonaktifkan, tidak dihapus dari database.
        $school = School::factory()->create();
        $admin = User::factory()->forSchool($school)->withRole(RoleName::SchoolAdmin)->create();
        $student = Student::factory()->create(['school_id' => $school->id]);

        $this->assertFalse($admin->can('delete', $student));
    }
}
