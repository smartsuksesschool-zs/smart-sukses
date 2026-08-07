<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fikih',
            'Bahasa Arab', 'Fisika', 'Kimia', 'Biologi', 'Sejarah',
        ]);

        return [
            'school_id' => School::factory(),
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).fake()->unique()->numberBetween(1, 999),
            'credit_hours' => fake()->numberBetween(2, 6),
            'is_active' => true,
        ];
    }
}
