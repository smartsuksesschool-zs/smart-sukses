<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Smart Sukses School '.fake()->unique()->city();

        return [
            'name' => $name,
            'code' => Str::upper(Str::slug(Str::afterLast($name, ' '))).fake()->unique()->numberBetween(1, 9999),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999),
            'primary_color' => '#1B3A6B',
            'secondary_color' => '#E07020',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
