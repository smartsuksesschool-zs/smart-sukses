<?php

namespace Tests\Feature\Grading;

use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Filament\Resources\GradeConfigResource\Pages\ListGradeConfigs;
use App\Filament\Resources\GradeResource\Pages\ListGrades;
use App\Filament\Resources\ReportCardResource\Pages\ListReportCards;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\GradeConfig;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Livewire\Livewire;

/**
 * AUTH-02 / Arsitektur 3.2 — isolasi data seluruh entitas Penilaian.
 * NFR 1.4: "Data isolation 100% — tidak ada kebocoran data antar tenant."
 */
class GradingTenantIsolationTest extends GradingTestCase
{
    protected School $otherSchool;

    protected Grade $foreignGrade;

    protected GradeConfig $foreignConfig;

    protected ReportCard $foreignReportCard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otherSchool = School::factory()->create();

        $year = AcademicYear::factory()->create(['school_id' => $this->otherSchool->id]);
        $subject = Subject::factory()->create(['school_id' => $this->otherSchool->id, 'code' => 'MTK']);
        $class = SchoolClass::factory()->create([
            'school_id' => $this->otherSchool->id,
            'academic_year_id' => $year->id,
        ]);
        $classSubject = ClassSubject::factory()->create([
            'school_id' => $this->otherSchool->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
        ]);
        $student = Student::factory()->create(['school_id' => $this->otherSchool->id]);

        $this->foreignConfig = GradeConfig::factory()->active()->create([
            'school_id' => $this->otherSchool->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $year->id,
        ]);

        $this->foreignGrade = Grade::factory()->create([
            'school_id' => $this->otherSchool->id,
            'student_id' => $student->id,
            'class_subject_id' => $classSubject->id,
            'academic_year_id' => $year->id,
        ]);

        $this->foreignReportCard = ReportCard::factory()->create([
            'school_id' => $this->otherSchool->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $year->id,
        ]);
    }

    public function test_every_grading_entity_is_scoped_to_the_current_school(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80);

        $this->assertSame(1, Grade::query()->count());
        $this->assertSame(1, GradeConfig::query()->count());

        $this->actingAs($this->admin);
        $this->assertSame(0, ReportCard::query()->count());
    }

    public function test_tables_never_show_records_of_another_school(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $own = $this->grade(GradeType::Daily, 80);

        Livewire::test(ListGrades::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$this->foreignGrade]);

        $this->actingAs($this->admin);

        Livewire::test(ListGradeConfigs::class)
            ->assertCanNotSeeTableRecords([$this->foreignConfig]);

        Livewire::test(ListReportCards::class)
            ->assertCanNotSeeTableRecords([$this->foreignReportCard]);
    }

    public function test_records_of_another_school_cannot_be_acted_upon(): void
    {
        $this->assertFalse($this->admin->can('view', $this->foreignGrade));
        $this->assertFalse($this->admin->can('update', $this->foreignGrade));
        $this->assertFalse($this->admin->can('view', $this->foreignConfig));
        $this->assertFalse($this->admin->can('update', $this->foreignConfig));
        $this->assertFalse($this->admin->can('activate', $this->foreignConfig));
        $this->assertFalse($this->admin->can('view', $this->foreignReportCard));
        $this->assertFalse($this->admin->can('publish', $this->foreignReportCard));
    }

    public function test_school_id_is_filled_automatically_on_create(): void
    {
        $this->actingAs($this->teacher);

        $grade = Grade::query()->create([
            'student_id' => $this->student->id,
            'class_subject_id' => $this->classSubject->id,
            'academic_year_id' => $this->year->id,
            'grade_type' => GradeType::Daily->value,
            'score' => 80,
            'graded_by' => $this->teacher->id,
        ]);

        $this->assertSame($this->school->id, $grade->school_id);
    }

    public function test_active_config_lookup_never_crosses_tenants(): void
    {
        // Cabang lain punya konfigurasi aktif untuk mapel berkode sama.
        $this->actingAs($this->teacher);

        $this->assertNull(GradeConfig::activeFor($this->subject->id, $this->year->id));

        $own = $this->activeConfig();

        $this->assertSame($own->id, GradeConfig::activeFor($this->subject->id, $this->year->id)?->id);
    }

    public function test_super_admin_sees_grading_data_of_all_schools(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();
        $this->grade(GradeType::Daily, 80);

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertSame(2, Grade::query()->count());
        $this->assertSame(2, GradeConfig::query()->count());
        $this->assertSame(1, ReportCard::query()->count());
    }

    public function test_a_teacher_of_another_school_cannot_grade_our_class_subject(): void
    {
        $foreignTeacher = User::factory()->forSchool($this->otherSchool)->withRole(RoleName::Guru)->create();

        $this->assertFalse($foreignTeacher->can('gradeClassSubject', [Grade::class, $this->classSubject]));
    }
}
