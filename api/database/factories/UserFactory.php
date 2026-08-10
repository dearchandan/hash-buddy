<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'phone' => '+9198'.fake()->unique()->numerify('########'),
            'phone_verified_at' => now(),
            'gender' => fake()->randomElement(Gender::cases()),
            'rating_avg' => 0,
            'rating_count' => 0,
            'rides_completed' => 0,
        ];
    }

    public function woman(): static
    {
        return $this->state(fn () => ['gender' => Gender::Female]);
    }

    public function man(): static
    {
        return $this->state(fn () => ['gender' => Gender::Male]);
    }

    public function rated(float $average, int $count = 10): static
    {
        return $this->state(fn () => [
            'rating_avg' => $average,
            'rating_count' => $count,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['blocked_at' => now()]);
    }
}
