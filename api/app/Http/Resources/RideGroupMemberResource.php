<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RideGroupMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'seats' => (int) $this->seats,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => new PublicUserResource($this->whenLoaded('user')),
        ];
    }
}
