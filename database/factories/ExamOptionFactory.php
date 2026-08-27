<?php

namespace Database\Factories;

use App\Models\ExamOption;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamOption>
 *
 * `school_id` diturunkan dari soalnya (butir 279). Bawaannya **bukan** kunci
 * jawaban: satu soal hanya punya satu jawaban benar, jadi yang benar harus
 * dinyatakan sekali dengan sengaja lewat `correct()`.
 */
class ExamOptionFactory extends Factory
{
    protected $model = ExamOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_question_id' => ExamQuestion::factory(),
            'school_id' => fn (array $attributes) => static::question($attributes)?->school_id,
            'option_text' => fake()->words(3, true),
            'is_correct' => false,
            'position' => 1,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn () => ['is_correct' => true]);
    }

    public function position(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    protected static function question(array $attributes): ?ExamQuestion
    {
        $key = $attributes['exam_question_id'] ?? null;

        if ($key === null) {
            return null;
        }

        return ExamQuestion::query()->withoutGlobalScope(SchoolScope::class)->find($key);
    }
}
