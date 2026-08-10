<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What one traveller may see about another. Phone numbers deliberately stay
 * out of this — travellers coordinate in the app until they are in a group
 * together.
 */
class PublicUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gender' => $this->gender?->value,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'rating_avg' => round((float) $this->rating_avg, 2),
            'rating_count' => (int) $this->rating_count,
            'rides_completed' => (int) $this->rides_completed,
        ];
    }
}
