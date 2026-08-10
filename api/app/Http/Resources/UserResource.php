<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in traveller's own profile.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'gender' => $this->gender?->value,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'rating_avg' => round((float) $this->rating_avg, 2),
            'rating_count' => (int) $this->rating_count,
            'rides_completed' => (int) $this->rides_completed,
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
        ];
    }
}
