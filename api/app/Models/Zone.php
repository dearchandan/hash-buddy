<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['city', 'name', 'slug', 'lat', 'lng', 'distance_km', 'sedan_fare', 'suv_fare', 'sort_order', 'is_active'])]
class Zone extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rides heading here that a stranger could still take a seat in.
     *
     * Exists as a relation rather than a query in the controller so the area
     * list can `withCount` it — the alternative is a count per zone on the
     * app's first screen.
     */
    public function openRides(): HasMany
    {
        return $this->hasMany(RideGroup::class)->joinable();
    }
}
