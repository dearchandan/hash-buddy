<?php

namespace App\Models;

use App\Enums\CabService;
use App\Enums\GenderPolicy;
use App\Enums\MemberStatus;
use App\Enums\RideGroupStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'code', 'created_by', 'airport_code', 'terminal', 'zone_id', 'window_start', 'window_end',
    'max_seats', 'gender_policy', 'meeting_point', 'quoted_fare', 'cab_service',
])]
class RideGroup extends Model
{
    use HasFactory;

    /** @see RideRequest::$attributes */
    protected $attributes = [
        'airport_code' => 'BLR',
        'seats_taken' => 0,
        'luggage_total' => 0,
        'gender_policy' => 'any',
        'status' => 'forming',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => RideGroupStatus::class,
            'gender_policy' => GenderPolicy::class,
            'cab_service' => CabService::class,
        ];
    }

    /**
     * What each traveller pays if this ride leaves as it stands.
     *
     * Only ever derived from a fare the host actually saw in Ola or Uber, never
     * from the seeded zone estimate — mixing the two would present a guess with
     * the authority of a quote.
     */
    public function fareShare(?int $extraSeats = null): ?int
    {
        if ($this->quoted_fare === null) {
            return null;
        }

        $seats = max(1, $this->seats_taken + ($extraSeats ?? 0));

        return (int) ceil($this->quoted_fare / $seats);
    }

    protected static function booted(): void
    {
        static::creating(function (RideGroup $group) {
            $group->code ??= static::generateCode();
        });
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(RideGroupMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', MemberStatus::Joined);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(CallSession::class);
    }

    public function scopeForming(Builder $query): Builder
    {
        return $query->where('status', RideGroupStatus::Forming);
    }

    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('window_start', '<', $end)->where('window_end', '>', $start);
    }

    /**
     * Rides a stranger could actually take a seat in right now.
     *
     * All three conditions matter. Status alone leaves full rides in the list;
     * seats alone leaves cancelled ones; and without the window check the
     * browse screen slowly fills with rides that departed hours ago, which is
     * how a discovery surface dies.
     */
    public function scopeJoinable(Builder $query): Builder
    {
        return $query->where('status', RideGroupStatus::Forming)
            ->whereColumn('seats_taken', '<', 'max_seats')
            ->where('window_end', '>', now());
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->max_seats - $this->seats_taken);
    }

    public function isFull(): bool
    {
        return $this->seatsAvailable() <= 0;
    }

    public function overlapMinutesWith(CarbonInterface $start, CarbonInterface $end): int
    {
        $from = $this->window_start->max($start);
        $to = $this->window_end->min($end);

        return $to->greaterThan($from) ? (int) $from->diffInMinutes($to) : 0;
    }

    public function hasMember(int $userId): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->where('status', MemberStatus::Joined)
            ->exists();
    }
}
