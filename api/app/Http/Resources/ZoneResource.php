<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city' => $this->city,
            'name' => $this->name,
            'slug' => $this->slug,
            'distance_km' => (int) $this->distance_km,
            'sedan_fare' => (int) $this->sedan_fare,
            'suv_fare' => (int) $this->suv_fare,

            // Present only where the caller asked for it, so the plain zone
            // picker does not pay for a count it never renders.
            'open_rides_count' => $this->whenCounted('openRides'),
            'seats_available' => $this->when(
                $this->seats_available !== null,
                fn (): int => (int) $this->seats_available,
            ),
            'next_departure' => $this->when(
                $this->next_departure !== null,
                fn (): ?string => $this->next_departure
                    ? Carbon::parse($this->next_departure)->toIso8601String()
                    : null,
            ),
        ];
    }
}
