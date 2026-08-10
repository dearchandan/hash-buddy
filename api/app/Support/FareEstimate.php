<?php

namespace App\Support;

/**
 * A rough, seeded fare picture for a shared ride — enough to show a traveller
 * what sharing is worth before they commit, not a quote.
 */
readonly class FareEstimate
{
    public function __construct(
        public string $vehicleClass,
        public int $totalFare,
        public int $perHeadFare,
        public int $soloFare,
        public int $savingsPerHead,
        public int $savingsPercent,
        public int $passengers,
        public int $luggage,
    ) {}

    public function toArray(): array
    {
        return [
            'vehicle_class' => $this->vehicleClass,
            'total_fare' => $this->totalFare,
            'per_head_fare' => $this->perHeadFare,
            'solo_fare' => $this->soloFare,
            'savings_per_head' => $this->savingsPerHead,
            'savings_percent' => $this->savingsPercent,
            'passengers' => $this->passengers,
            'luggage' => $this->luggage,
            'currency' => 'INR',
        ];
    }
}
