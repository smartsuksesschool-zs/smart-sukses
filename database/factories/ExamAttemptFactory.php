<?php

namespace Database\Factories;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Scopes\SchoolScope;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamAttempt>
 *
 * `school_id` dan siswanya diturunkan dari ujiannya, sehingga bawaan factory
 * ini selalu satu cabang (butir 279). Test lintas cabang menuliskan
 * `student_id` atau `school_id`-nya sendiri, dan factory tidak
 * memperbaikinya.
 */
class ExamAttemptFactory extends Factory
{
    protected $model = ExamAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'school_id' => fn (array $attributes) => static::exam($attributes)?->school_id,
            'student_id' => fn (array $attributes) => Student::factory()->create([
                'school_id' => static::exam($attributes)?->school_id,
            ])->getKey(),
            'status' => ExamAttemptStatus::InProgress->value,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(60),
            'submitted_at' => null,
            'score' => null,
            'grade_id' => null,
        ];
    }

    /**
     * Pengerjaan yang sudah selesai dan sudah bernilai — keadaan yang dihasilkan
     * penilaian di sisi server, bukan sesuatu yang dapat dikirim peramban.
     */
    public function submitted(float $score): static
    {
        return $this->state(fn () => [
            'status' => ExamAttemptStatus::Submitted->value,
            'submitted_at' => now(),
            'score' => $score,
        ]);
    }

    public function forStudent(Student $student): static
    {
        return $this->state(fn () => ['student_id' => $student->getKey()]);
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
