<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'airport_code' => $this->airport_code,
            'terminal' => $this->terminal,
            'zone' => new ZoneResource($this->whenLoaded('zone')),
            'zone_id' => $this->zone_id,
            'drop_landmark' => $this->drop_landmark,
            'flight_number' => $this->flight_number,
            'window_start' => $this->window_start->toIso8601String(),
            'window_end' => $this->window_end->toIso8601String(),
            'seats' => (int) $this->seats,
            'luggage_count' => (int) $this->luggage_count,
            'gender_preference' => $this->gender_preference->value,
            'note' => $this->note,
            'ride_group_id' => $this->ride_group_id,
            'group' => new RideGroupResource($this->whenLoaded('group')),
            'user' => new PublicUserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
