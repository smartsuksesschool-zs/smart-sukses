<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\School;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'nis' => (string) fake()->unique()->numberBetween(100000, 999999),
            'nisn' => (string) fake()->unique()->numberBetween(1000000000, 9999999999),
            'full_name' => fake()->name(),
            'gender' => fake()->randomElement(Gender::cases())->value,
            'birth_place' => fake()->city(),
            'birth_date' => fake()->date(),
            'religion' => 'Islam',
            'parent_name' => fake()->name(),
            'parent_phone' => fake()->numerify('08##########'),
            'entry_year' => fake()->numberBetween(2020, 2026),
            'status' => StudentStatus::Active->value,
        ];
    }

    public function graduated(): static
    {
        return $this->state(fn () => ['status' => StudentStatus::Graduated->value]);
    }
}
