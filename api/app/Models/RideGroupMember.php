<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ride_group_id', 'user_id', 'ride_request_id', 'role', 'status', 'seats',
    'joined_at', 'left_at', 'last_read_message_id',
])]
class RideGroupMember extends Model
{
    use HasFactory;

    /** @see RideRequest::$attributes */
    protected $attributes = [
        'role' => 'member',
        'status' => 'joined',
        'seats' => 1,
        'last_read_message_id' => 0,
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'role' => MemberRole::class,
            'status' => MemberStatus::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RideGroup::class, 'ride_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function isHost(): bool
    {
        return $this->role === MemberRole::Host;
    }
}
