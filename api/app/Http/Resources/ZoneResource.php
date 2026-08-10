<?php

namespace App\Http\Resources;

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
        ];
    }
}
