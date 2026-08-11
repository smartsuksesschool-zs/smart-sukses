<?php

namespace Database\Factories;

use App\Enums\GradeConfigStatus;
use App\Enums\GradeType;
use App\Models\AcademicYear;
use App\Models\GradeConfig;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeConfig>
 */
class GradeConfigFactory extends Factory
{
    protected $model = GradeConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'subject_id' => Subject::factory(),
            'academic_year_id' => AcademicYear::factory(),
            // Contoh NILAI-02 poin 1: Harian 40%, UTS 30%, UAS 30%.
            'components' => [
                ['type' => GradeType::Daily->value, 'weight' => 0.40],
                ['type' => GradeType::Midterm->value, 'weight' => 0.30],
                ['type' => GradeType::Final->value, 'weight' => 0.30],
            ],
            'created_by' => User::factory(),
            'version' => 1,
            'status' => GradeConfigStatus::Draft->value,
        ];
    }

    /**
     * @param  array<int, array{type: string, weight: float}>  $components
     */
    public function components(array $components): static
    {
        return $this->state(fn () => ['components' => $components]);
    }

    public function status(GradeConfigStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'activated_at' => $status === GradeConfigStatus::Draft ? null : now(),
            'locked_at' => $status === GradeConfigStatus::Locked ? now() : null,
        ]);
    }

    public function active(): static
    {
        return $this->status(GradeConfigStatus::Active);
    }

    public function locked(): static
    {
        return $this->status(GradeConfigStatus::Locked);
    }
}
