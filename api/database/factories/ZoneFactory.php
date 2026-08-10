<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    public function definition(): array
    {
        $name = fake()->unique()->streetName();
        $sedan = fake()->numberBetween(700, 1700);

        return [
            'city' => 'Bengaluru',
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'lat' => fake()->latitude(12.8, 13.2),
            'lng' => fake()->longitude(77.5, 77.8),
            'distance_km' => fake()->numberBetween(15, 60),
            'sedan_fare' => $sedan,
            'suv_fare' => (int) round($sedan * 1.6),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
