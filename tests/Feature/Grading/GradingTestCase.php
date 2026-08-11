<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fondasi bersama untuk pengujian modul Akademik: satu cabang lengkap dengan
 * tahun ajaran aktif, kelas, wali kelas, guru mapel, dan satu siswa.
 */
abstract class GradingTestCase extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected AcademicYear $year;

    protected Subject $subject;

    protected SchoolClass $class;

    protected ClassSubject $classSubject;

    protected Student $student;

    protected User $admin;

    protected User $teacher;

    protected User $homeroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->school = School::factory()->create();

        $this->admin = User::factory()->forSchool($this->school)->withRole(RoleName::SchoolAdmin)->create();
        $this->teacher = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();
        $this->homeroom = User::factory()->forSchool($this->school)->withRole(RoleName::WaliKelas)->create();

        $this->year = AcademicYear::factory()->create([
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->subject = Subject::factory()->create([
            'school_id' => $this->school->id,
            'code' => 'MTK',
            'name' => 'Matematika',
        ]);

        $this->class = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'homeroom_teacher_id' => $this->homeroom->id,
        ]);

        $this->classSubject = ClassSubject::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->student = Student::factory()->create(['school_id' => $this->school->id]);

        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
        ]);
    }

    /**
     * Konfigurasi bobot default: Harian 40%, UTS 30%, UAS 30% (NILAI-02 poin 1).
     *
     * @param  array<int, array{type: string, weight: float}>|null  $components
     */
    protected function activeConfig(?array $components = null, ?Subject $subject = null): GradeConfig
    {
        return GradeConfig::factory()->active()->create([
            'school_id' => $this->school->id,
            'subject_id' => ($subject ?? $this->subject)->id,
            'academic_year_id' => $this->year->id,
            'created_by' => $this->admin->id,
            'components' => $components ?? [
                ['type' => GradeType::Daily->value, 'weight' => 0.40],
                ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                ['type' => GradeType::Final->value, 'weight' => 0.30],
            ],
        ]);
    }

    /**
     * Menyimpan satu nilai melalui model, sehingga hook snapshot bobot ikut jalan.
     */
    protected function grade(
        GradeType $type,
        float $score,
        ?AssessmentType $assessment = null,
        ?Student $student = null,
        ?ClassSubject $classSubject = null,
    ): Grade {
        return Grade::query()->create([
            'school_id' => $this->school->id,
            'student_id' => ($student ?? $this->student)->id,
            'class_subject_id' => ($classSubject ?? $this->classSubject)->id,
            'academic_year_id' => $this->year->id,
            'grade_type' => $type->value,
            'assessment_type' => ($assessment ?? AssessmentType::Summative)->value,
            'score' => $score,
            'graded_by' => $this->teacher->id,
            'graded_at' => now(),
        ]);
    }
}
