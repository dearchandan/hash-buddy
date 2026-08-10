<?php

namespace Database\Factories;

use App\Enums\GenderPolicy;
use App\Enums\RideRequestStatus;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RideRequest>
 */
class RideRequestFactory extends Factory
{
    protected $model = RideRequest::class;

    public function definition(): array
    {
        $start = now()->addHours(fake()->numberBetween(1, 12))->startOfMinute();

        return [
            'user_id' => User::factory(),
            'airport_code' => 'BLR',
            'terminal' => fake()->randomElement(['T1', 'T2']),
            'zone_id' => Zone::factory(),
            'drop_landmark' => fake()->optional()->streetName(),
            'flight_number' => fake()->optional()->bothify('6E###'),
            'window_start' => $start,
            'window_end' => $start->copy()->addMinutes(45),
            'seats' => 1,
            'luggage_count' => fake()->numberBetween(1, 2),
            'gender_preference' => GenderPolicy::Any,
            'status' => RideRequestStatus::Open,
        ];
    }

    /**
     * Pin the request to an exact window so matching tests stay deterministic.
     */
    public function window(\DateTimeInterface $start, \DateTimeInterface $end): static
    {
        return $this->state(fn () => [
            'window_start' => $start,
            'window_end' => $end,
        ]);
    }

    public function womenOnly(): static
    {
        return $this->state(fn () => ['gender_preference' => GenderPolicy::WomenOnly]);
    }
}
