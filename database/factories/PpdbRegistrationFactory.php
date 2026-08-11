<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\PpdbStatus;
use App\Models\PpdbRegistration;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PpdbRegistration>
 */
class PpdbRegistrationFactory extends Factory
{
    protected $model = PpdbRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => null,
            // Panjang mengikuti ERD: reg_number VARCHAR(20).
            'reg_number' => Str::upper(Str::random(4)).'-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'full_name' => fake()->name(),
            'gender' => fake()->randomElement(Gender::cases())->value,
            'birth_date' => fake()->dateTimeBetween('-18 years', '-14 years')->format('Y-m-d'),
            'origin_school' => 'SMP Negeri '.fake()->numberBetween(1, 30),
            'parent_name' => fake()->name(),
            'parent_phone' => fake()->numerify('08##########'),
            'parent_email' => fake()->unique()->safeEmail(),
            'documents' => null,
            'status' => PpdbStatus::Registered->value,
            'status_notes' => null,
            'converted_student_id' => null,
            'registered_at' => now(),
        ];
    }

    public function status(PpdbStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function passed(): static
    {
        return $this->status(PpdbStatus::Passed);
    }
}
