<?php

namespace App\Models;

use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ride_group_id', 'caller_id', 'callee_id', 'status', 'offer_sdp', 'answer_sdp'])]
class CallSession extends Model
{
    use HasFactory;

    /** @see RideRequest::$attributes */
    protected $attributes = [
        'status' => 'ringing',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RideGroup::class, 'ride_group_id');
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [CallStatus::Ringing, CallStatus::Accepted]);
    }

    public function involves(int $userId): bool
    {
        return $this->caller_id === $userId || $this->callee_id === $userId;
    }

    public function otherParty(int $userId): int
    {
        return $this->caller_id === $userId ? $this->callee_id : $this->caller_id;
    }
}
