<?php

namespace App\Services;

use App\Models\Zone;
use App\Support\FareEstimate;

class FareEstimator
{
    /**
     * Estimate what a shared ride costs each traveller.
     *
     * The savings figure deliberately compares against a solo *sedan*, because
     * that is what the traveller would actually have booked alone. Once a group
     * outgrows a sedan the estimate upgrades the vehicle instead of dividing a
     * sedan fare three ways, which is the mistake that makes sharing look far
     * more lucrative on paper than it is at the kerb.
     */
    public function estimate(Zone $zone, int $passengers, int $luggage): FareEstimate
    {
        $passengers = max(1, $passengers);

        $needsSuv = $passengers > config('hashbuddy.vehicle.sedan_max_passengers')
            || $luggage > config('hashbuddy.vehicle.sedan_max_luggage');

        $vehicleClass = $needsSuv ? 'suv' : 'sedan';
        $totalFare = $needsSuv ? $zone->suv_fare : $zone->sedan_fare;

        $perHead = (int) ceil($totalFare / $passengers);
        $soloFare = $zone->sedan_fare;
        $savings = max(0, $soloFare - $perHead);

        return new FareEstimate(
            vehicleClass: $vehicleClass,
            totalFare: $totalFare,
            perHeadFare: $perHead,
            soloFare: $soloFare,
            savingsPerHead: $savings,
            savingsPercent: $soloFare > 0 ? (int) round($savings / $soloFare * 100) : 0,
            passengers: $passengers,
            luggage: $luggage,
        );
    }
}
