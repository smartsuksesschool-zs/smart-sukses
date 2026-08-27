<?php

namespace Database\Factories;

use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAnswer>
 *
 * Bawaannya konsisten dua lapis: `school_id` mengikuti pengerjaannya, dan
 * soalnya dibuat pada ujian yang sedang dikerjakan itu juga (butir 279).
 * Tanpa lapis kedua, jawaban bawaan akan selalu menunjuk soal dari ujian lain —
 * persis keadaan tidak sah yang justru harus dapat ditolak.
 */
class ExamAnswerFactory extends Factory
{
    protected $model = ExamAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_attempt_id' => ExamAttempt::factory(),
            'school_id' => fn (array $attributes) => static::attempt($attributes)?->school_id,
            'exam_question_id' => fn (array $attributes) => ExamQuestion::factory()->create([
                'exam_id' => static::attempt($attributes)?->exam_id,
            ])->getKey(),
            'exam_option_id' => null,
            'answer_text' => null,
            'is_correct' => null,
            'points_earned' => null,
        ];
    }

    /**
     * Jawaban yang sudah dinilai. Nilainya snapshot, sama seperti yang ditulis
     * penilaian di sisi server.
     */
    public function scored(bool $isCorrect, float $pointsEarned): static
    {
        return $this->state(fn () => [
            'is_correct' => $isCorrect,
            'points_earned' => $pointsEarned,
        ]);
    }

    protected static function attempt(array $attributes): ?ExamAttempt
    {
        $key = $attributes['exam_attempt_id'] ?? null;

        if ($key === null) {
            return null;
        }

        return ExamAttempt::query()->withoutGlobalScope(SchoolScope::class)->find($key);
    }
}
