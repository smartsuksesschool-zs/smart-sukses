<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('08##########'),
            'locale' => 'id',
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forSchool(School $school): static
    {
        return $this->state(fn () => ['school_id' => $school->id]);
    }

    /**
     * Super Admin adalah peran Platform Level: school_id = NULL.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => ['school_id' => null])
            ->afterCreating(fn (User $user) => $user->syncRoles([RoleName::SuperAdmin->value]));
    }

    public function withRole(RoleName $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$role->value]));
    }
}
