<?php

namespace Database\Factories;

use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamQuestion>
 *
 * `school_id` diturunkan dari ujiannya (butir 279). Bawaannya pilihan ganda:
 * itu satu-satunya jenis yang didukung rilis ini.
 */
class ExamQuestionFactory extends Factory
{
    protected $model = ExamQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'school_id' => fn (array $attributes) => static::exam($attributes)?->school_id,
            'question_type' => ExamQuestionType::MultipleChoice->value,
            'question_text' => fake()->sentence().'?',
            'points' => 1.00,
            'position' => 1,
        ];
    }

    public function points(float $points): static
    {
        return $this->state(fn () => ['points' => $points]);
    }

    public function position(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    /**
     * Soal uraian. Skema menerimanya; aplikasi rilis ini tidak. Disediakan agar
     * test dapat membuktikan penolakan itu tanpa menulis SQL mentah.
     */
    public function essay(): static
    {
        return $this->state(fn () => ['question_type' => ExamQuestionType::Essay->value]);
    }

    protected static function exam(array $attributes): ?Exam
    {
        $key = $attributes['exam_id'] ?? null;

        if ($key === null) {
            return null;
        }

        return Exam::query()->withoutGlobalScope(SchoolScope::class)->find($key);
    }
}
