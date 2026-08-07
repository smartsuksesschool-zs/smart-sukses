<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'name' => fake()->randomElement(['X', 'XI', 'XII']).'-'.fake()->unique()->randomLetter(),
            'grade_level' => fake()->randomElement([10, 11, 12]),
            'room' => 'R'.fake()->numberBetween(101, 320),
            'capacity' => 35,
        ];
    }

    public function capacity(int $capacity): static
    {
        return $this->state(fn () => ['capacity' => $capacity]);
    }
}
