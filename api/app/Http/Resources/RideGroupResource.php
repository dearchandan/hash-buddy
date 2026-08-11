<?php

namespace App\Http\Resources;

use App\Services\FareEstimator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerIsMember = $request->user() && $this->hasMember($request->user()->id);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'airport_code' => $this->airport_code,
            'terminal' => $this->terminal,
            'zone' => new ZoneResource($this->whenLoaded('zone')),
            'zone_id' => $this->zone_id,
            'window_start' => $this->window_start->toIso8601String(),
            'window_end' => $this->window_end->toIso8601String(),
            'max_seats' => (int) $this->max_seats,
            'seats_taken' => (int) $this->seats_taken,
            'seats_available' => $this->seatsAvailable(),
            'luggage_total' => (int) $this->luggage_total,
            'gender_policy' => $this->gender_policy->value,
            'meeting_point' => $this->meeting_point,

            // A fare the host actually saw in Ola or Uber, and the share each
            // traveller pays at the ride's current occupancy. Null when nobody
            // checked — kept separate from fare_estimate below, which is a
            // seeded guess and must never be mistaken for a quote.
            'quoted_fare' => $this->quoted_fare === null ? null : (int) $this->quoted_fare,
            'fare_share' => $this->fareShare(),
            'cab_service' => $this->cab_service?->value,
            'cab_service_label' => $this->cab_service?->label(),
            'is_member' => (bool) $viewerIsMember,
            'fare_estimate' => $this->when(
                $this->relationLoaded('zone') && $this->zone !== null,
                fn () => app(FareEstimator::class)
                    ->estimate($this->zone, max(1, (int) $this->seats_taken), (int) $this->luggage_total)
                    ->toArray(),
            ),
            'members' => RideGroupMemberResource::collection($this->whenLoaded('activeMembers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
