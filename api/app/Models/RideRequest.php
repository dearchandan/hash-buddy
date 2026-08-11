<?php

namespace App\Models;

use App\Enums\CabService;
use App\Enums\GenderPolicy;
use App\Enums\RideRequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'airport_code', 'terminal', 'zone_id', 'drop_landmark', 'flight_number',
    'window_start', 'window_end', 'seats', 'luggage_count', 'gender_preference', 'note',
    'quoted_fare', 'cab_service', 'meeting_point',
])]
class RideRequest extends Model
{
    use HasFactory;

    /**
     * Mirrors the column defaults so a freshly created model reads the same in
     * memory as it does after a round trip to the database.
     */
    protected $attributes = [
        'airport_code' => 'BLR',
        'seats' => 1,
        'luggage_count' => 1,
        'gender_preference' => 'any',
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'matched_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => RideRequestStatus::class,
            'gender_preference' => GenderPolicy::class,
            'cab_service' => CabService::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RideGroup::class, 'ride_group_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RideRequestStatus::Open);
    }

    /**
     * Requests whose departure window overlaps [$start, $end] at all. The
     * minimum-overlap threshold is applied in MatchingService, not here.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('window_start', '<', $end)->where('window_end', '>', $start);
    }

    /**
     * Minutes this request's window shares with another window. Zero or less
     * means they never coincide.
     */
    public function overlapMinutesWith(CarbonInterface $start, CarbonInterface $end): int
    {
        $from = $this->window_start->max($start);
        $to = $this->window_end->min($end);

        return $to->greaterThan($from) ? (int) $from->diffInMinutes($to) : 0;
    }

    public function isOpen(): bool
    {
        return $this->status === RideRequestStatus::Open;
    }
}
