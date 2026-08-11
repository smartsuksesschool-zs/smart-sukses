<?php

namespace Database\Factories;

use App\Enums\AssessmentType;
use App\Enums\GradeType;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    protected $model = Grade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'class_subject_id' => ClassSubject::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'grade_type' => GradeType::Daily->value,
            'score' => fake()->randomFloat(2, 60, 100),
            'weight' => null,
            'description' => null,
            'graded_by' => User::factory(),
            'graded_at' => now(),
            'assessment_type' => AssessmentType::Summative->value,
            'grade_config_id' => null,
        ];
    }

    public function type(GradeType $type): static
    {
        return $this->state(fn () => ['grade_type' => $type->value]);
    }

    public function score(float $score): static
    {
        return $this->state(fn () => ['score' => $score]);
    }

    public function weight(?float $weight): static
    {
        return $this->state(fn () => ['weight' => $weight]);
    }

    public function formative(): static
    {
        return $this->state(fn () => ['assessment_type' => AssessmentType::Formative->value]);
    }

    public function summative(): static
    {
        return $this->state(fn () => ['assessment_type' => AssessmentType::Summative->value]);
    }
}
