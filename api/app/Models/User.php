<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\MemberStatus;
use App\Enums\RideGroupStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'phone', 'gender', 'avatar_url', 'bio', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'blocked_at' => 'datetime',
            'password' => 'hashed',
            'gender' => Gender::class,
            'rating_avg' => 'float',
        ];
    }

    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(RideGroupMember::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Rides the user is currently on and that are still happening.
     *
     * Closed and completed rides are excluded rather than merely styled
     * differently: once a ride is over it is not something the traveller can
     * act on, and leaving it on the home screen makes a finished trip look
     * like a live one.
     */
    public function activeGroups()
    {
        return RideGroup::query()
            ->whereIn(
                'id',
                $this->memberships()->where('status', MemberStatus::Joined)->select('ride_group_id')
            )
            ->whereNotIn('status', [RideGroupStatus::Cancelled, RideGroupStatus::Completed]);
    }
}
