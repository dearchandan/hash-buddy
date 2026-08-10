<?php

namespace Tests\Unit;

use App\Models\Zone;
use App\Services\FareEstimator;
use Tests\TestCase;

class FareEstimatorTest extends TestCase
{
    private function zone(): Zone
    {
        return new Zone(['sedan_fare' => 1250, 'suv_fare' => 2000]);
    }

    public function test_a_pair_splits_a_sedan_and_halves_the_fare(): void
    {
        $estimate = app(FareEstimator::class)->estimate($this->zone(), passengers: 2, luggage: 2);

        $this->assertSame('sedan', $estimate->vehicleClass);
        $this->assertSame(625, $estimate->perHeadFare);
        $this->assertSame(625, $estimate->savingsPerHead);
        $this->assertSame(50, $estimate->savingsPercent);
    }

    public function test_three_travellers_need_an_suv_so_the_saving_is_well_under_two_thirds(): void
    {
        $estimate = app(FareEstimator::class)->estimate($this->zone(), passengers: 3, luggage: 3);

        $this->assertSame('suv', $estimate->vehicleClass);
        $this->assertSame(667, $estimate->perHeadFare);

        // The naive "solo fare / 3" answer would be ₹417 and a 67% saving.
        // Charging the right vehicle class puts the real figure near 47%.
        $this->assertSame(47, $estimate->savingsPercent);
        $this->assertLessThan(60, $estimate->savingsPercent);
    }

    public function test_a_pair_with_heavy_luggage_still_needs_an_suv(): void
    {
        $estimate = app(FareEstimator::class)->estimate($this->zone(), passengers: 2, luggage: 4);

        $this->assertSame('suv', $estimate->vehicleClass);
        $this->assertSame(1000, $estimate->perHeadFare);
    }

    public function test_travelling_alone_saves_nothing(): void
    {
        $estimate = app(FareEstimator::class)->estimate($this->zone(), passengers: 1, luggage: 1);

        $this->assertSame(1250, $estimate->perHeadFare);
        $this->assertSame(0, $estimate->savingsPerHead);
    }
}
