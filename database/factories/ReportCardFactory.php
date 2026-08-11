<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportCard>
 */
class ReportCardFactory extends Factory
{
    protected $model = ReportCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'student_id' => Student::factory(),
            'class_id' => SchoolClass::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'final_scores' => [],
            'attitude_score' => null,
            'is_published' => false,
        ];
    }

    /**
     * @param  array<string, float>  $scores
     */
    public function scores(array $scores): static
    {
        return $this->state(fn () => ['final_scores' => $scores]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
