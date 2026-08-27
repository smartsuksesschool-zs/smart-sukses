<?php

namespace Database\Factories;

use App\Enums\ExamStatus;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Scopes\SchoolScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exam>
 *
 * Berbeda dari factory lama di project ini — yang memberi setiap FK
 * `School::factory()`-nya sendiri dan karena itu menghasilkan baris lintas
 * cabang — factory CBT menurunkan `school_id` dan `academic_year_id` dari
 * kelas-mapelnya. Alasannya: seluruh yang diuji pada CBT justru **kesesuaian
 * cabang**, sehingga bawaan yang tidak konsisten akan membuat setiap test
 * dimulai dari keadaan tidak sah (butir 279).
 *
 * Yang diturunkan tetap dapat ditimpa. Menuliskan `school_id` lain secara
 * eksplisit menghasilkan baris lintas cabang sungguhan — dan itu memang yang
 * dibutuhkan test negatif. Factory ini tidak pernah memperbaiki apa pun
 * diam-diam.
 */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_subject_id' => ClassSubject::factory(),
            'school_id' => fn (array $attributes) => static::classSubject($attributes)?->school_id,
            'academic_year_id' => fn (array $attributes) => static::classSubject($attributes)?->academic_year_id,
            'title' => 'Ulangan '.fake()->words(2, true),
            'description' => null,
            'duration_minutes' => 60,
            'available_from' => now()->subHour(),
            'available_until' => now()->addDay(),
            'status' => ExamStatus::Draft->value,
            'created_by' => fn (array $attributes) => User::factory()->state([
                'school_id' => static::classSubject($attributes)?->school_id,
            ]),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => ExamStatus::Published->value]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => ExamStatus::Closed->value]);
    }

    /**
     * Rentang waktu eksplisit — dipakai test yang memang menguji jadwalnya.
     */
    public function window(mixed $from, mixed $until): static
    {
        return $this->state(fn () => [
            'available_from' => $from,
            'available_until' => $until,
        ]);
    }

    /**
     * Kelas-mapel yang sudah terbentuk untuk baris ini.
     *
     * Scope dilepas supaya factory tetap bekerja di dalam test yang sedang
     * berjalan sebagai pengguna sebuah cabang — termasuk saat sengaja
     * membangun data cabang lain.
     */
    protected static function classSubject(array $attributes): ?ClassSubject
    {
        $key = $attributes['class_subject_id'] ?? null;

        if ($key === null) {
            return null;
        }

        return ClassSubject::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->find($key);
    }
}
