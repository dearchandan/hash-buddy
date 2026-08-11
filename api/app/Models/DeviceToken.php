<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One push destination. A registration token identifies an app install, not a
 * person, so the same handset re-registers under a new token after a reinstall
 * and the old row is pruned when FCM reports it unregistered.
 */
#[Fillable(['user_id', 'token', 'token_hash', 'platform', 'last_used_at'])]
#[Hidden(['token', 'token_hash'])]
class DeviceToken extends Model
{
    use HasFactory;

    protected $attributes = [
        'platform' => 'android',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
