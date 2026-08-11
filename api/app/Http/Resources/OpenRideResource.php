<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ride as a stranger browsing an area sees it, before they join.
 *
 * Everything here is deliberately public to any signed-in traveller: this is a
 * discovery surface, and someone deciding whether to share a car with a person
 * needs to see who they are. Phone numbers are not part of that — those stay
 * out of PublicUserResource and only chat and calls bridge the gap, after
 * joining.
 */
class OpenRideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $seatsFree = $this->seatsAvailable();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'terminal' => $this->terminal,
            'zone' => new ZoneResource($this->whenLoaded('zone')),

            'window_start' => $this->window_start?->toIso8601String(),
            'window_end' => $this->window_end?->toIso8601String(),

            'seats_taken' => (int) $this->seats_taken,
            'max_seats' => (int) $this->max_seats,
            'seats_available' => $seatsFree,

            // Null whenever the host did not check a fare before opening the
            // ride, which is a normal thing to have done. The app says "fare
            // not shared yet" rather than substituting the seeded estimate,
            // because a guess shown in the same slot as a quote reads as one.
            'quoted_fare' => $this->quoted_fare === null ? null : (int) $this->quoted_fare,
            // What you would pay if you took one seat and nobody else joined.
            // The pessimistic number on purpose: it can only improve.
            'fare_share' => $this->fareShare(1),

            'cab_service' => $this->cab_service?->value,
            'cab_service_label' => $this->cab_service?->label(),
            'meeting_point' => $this->meeting_point,

            'is_women_only' => $this->gender_policy->value === 'women_only',
            'members' => RideGroupMemberResource::collection($this->whenLoaded('activeMembers')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
