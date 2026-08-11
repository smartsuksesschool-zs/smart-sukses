<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Enums\RoleName;
use App\Filament\Pages\InputNilai;
use App\Filament\Resources\GradeResource\Pages\CreateGrade;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Subject;
use App\Models\User;
use Livewire\Livewire;

/**
 * NILAI-01 — input nilai per komponen, satuan maupun massal.
 * API 4.8 — "Guru hanya bisa input nilai untuk kelas yang dia ampu".
 */
class GradeInputTest extends GradingTestCase
{
    public function test_teacher_can_record_a_single_grade(): void
    {
        $this->actingAs($this->teacher);
        $this->activeConfig();

        Livewire::test(CreateGrade::class)
            ->fillForm([
                'class_subject_id' => $this->classSubject->id,
                'student_id' => $this->student->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'score' => 85,
                'description' => 'Ulangan Harian Bab 3',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $grade = Grade::query()->firstOrFail();

        $this->assertSame('85.00', $grade->score);
        $this->assertSame($this->year->id, $grade->academic_year_id);
        $this->assertSame($this->teacher->id, $grade->graded_by);
        $this->assertSame('0.40', $grade->weight);
    }

    public function test_score_must_be_within_zero_to_one_hundred(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(CreateGrade::class)
            ->fillForm([
                'class_subject_id' => $this->classSubject->id,
                'student_id' => $this->student->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'score' => 120,
            ])
            ->call('create')
            ->assertHasFormErrors(['score']);

        Livewire::test(CreateGrade::class)
            ->fillForm([
                'class_subject_id' => $this->classSubject->id,
                'student_id' => $this->student->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'score' => -5,
            ])
            ->call('create')
            ->assertHasFormErrors(['score']);
    }

    public function test_teacher_cannot_grade_a_class_subject_they_do_not_teach(): void
    {
        $otherTeacher = User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create();

        $this->actingAs($otherTeacher);

        $this->assertFalse($otherTeacher->can('gradeClassSubject', [Grade::class, $this->classSubject]));

        Livewire::test(CreateGrade::class)
            ->fillForm([
                'class_subject_id' => $this->classSubject->id,
                'student_id' => $this->student->id,
                'grade_type' => GradeType::Daily->value,
                'assessment_type' => AssessmentType::Summative->value,
                'score' => 80,
            ])
            ->call('create')
            ->assertHasFormErrors(['class_subject_id']);

        $this->assertSame(0, Grade::query()->count());
    }

    public function test_school_admin_is_not_restricted_to_owned_class_subjects(): void
    {
        // Matriks 1.1.2 — "Input Nilai" untuk SCHOOL_ADMIN adalah akses penuh.
        $this->actingAs($this->admin);

        $this->assertTrue($this->admin->can('gradeClassSubject', [Grade::class, $this->classSubject]));
    }

    public function test_assessment_type_is_stored(): void
    {
        $this->actingAs($this->teacher);

        $formative = $this->grade(GradeType::Daily, 70, AssessmentType::Formative);
        $summative = $this->grade(GradeType::Daily, 90, AssessmentType::Summative);

        $this->assertSame(AssessmentType::Formative, $formative->fresh()->assessment_type);
        $this->assertSame(AssessmentType::Summative, $summative->fresh()->assessment_type);
    }

    public function test_bulk_page_lists_students_and_saves_their_scores(): void
    {
        // API 4.8 — POST /grades/bulk.
        $second = Student::factory()->create(['school_id' => $this->school->id, 'full_name' => 'Budi']);
        StudentClass::factory()->create([
            'school_id' => $this->school->id,
            'student_id' => $second->id,
            'class_id' => $this->class->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->actingAs($this->teacher);
        $this->activeConfig();

        $component = Livewire::test(InputNilai::class)
            ->set('data.class_subject_id', $this->classSubject->id)
            ->call('refreshStudentRows');

        $rows = $component->get('data.rows');
        $this->assertCount(2, $rows);

        $component
            ->set('data.grade_type', GradeType::Midterm->value)
            ->set('data.assessment_type', AssessmentType::Summative->value)
            ->set('data.rows.0.score', 88)
            ->set('data.rows.1.score', 92)
            ->call('save');

        $this->assertSame(2, Grade::query()->count());
        $this->assertSame(
            ['88.00', '92.00'],
            Grade::query()->orderBy('id')->pluck('score')->all(),
        );
        $this->assertSame('0.30', Grade::query()->first()->weight);
    }

    public function test_bulk_page_skips_students_left_blank(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(InputNilai::class)
            ->set('data.class_subject_id', $this->classSubject->id)
            ->call('refreshStudentRows')
            ->set('data.grade_type', GradeType::Daily->value)
            ->set('data.assessment_type', AssessmentType::Summative->value)
            ->set('data.rows.0.score', null)
            ->call('save');

        $this->assertSame(0, Grade::query()->count());
    }

    public function test_bulk_page_only_offers_class_subjects_taught_by_the_teacher(): void
    {
        $otherClassSubject = ClassSubject::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id, 'code' => 'BIN'])->id,
            'teacher_id' => User::factory()->forSchool($this->school)->withRole(RoleName::Guru)->create()->id,
            'academic_year_id' => $this->year->id,
        ]);

        $this->actingAs($this->teacher);

        Livewire::test(InputNilai::class)
            ->set('data.class_subject_id', $otherClassSubject->id)
            ->call('refreshStudentRows')
            ->set('data.grade_type', GradeType::Daily->value)
            ->set('data.assessment_type', AssessmentType::Summative->value)
            ->set('data.rows.0.score', 80)
            ->call('save');

        $this->assertSame(0, Grade::query()->count());
    }
}
