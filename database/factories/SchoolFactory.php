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
        $city = fake()->city();
        $suffix = (string) fake()->unique()->numberBetween(1, 9999);

        // Panjang dipotong mengikuti batas kolom ERD 2.2:
        // code VARCHAR(20), slug VARCHAR(50) — MySQL menolak nilai yang melebihi.
        return [
            'name' => Str::limit('Smart Sukses School '.$city, 150, ''),
            'code' => Str::upper(Str::substr(Str::slug($city, ''), 0, 10)).$suffix,
            'slug' => Str::substr(Str::slug($city), 0, 40).'-'.$suffix,
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
