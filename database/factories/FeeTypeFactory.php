<?php

namespace Database\Factories;

use App\Enums\FeeFrequency;
use App\Models\FeeType;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeType>
 */
class FeeTypeFactory extends Factory
{
    protected $model = FeeType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->randomElement(['SPP', 'Uang Gedung', 'Kegiatan OSIS', 'Seragam']),
            'amount' => fake()->numberBetween(1, 20) * 50_000,
            'frequency' => FeeFrequency::Monthly->value,
            'academic_year_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function frequency(FeeFrequency $frequency): static
    {
        return $this->state(fn () => ['frequency' => $frequency->value]);
    }
}
