<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ride_group_id', 'user_id', 'type', 'body'])]
class Message extends Model
{
    use HasFactory;

    /** @see RideRequest::$attributes */
    protected $attributes = [
        'type' => 'text',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
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

    public function isSystem(): bool
    {
        return $this->type === MessageType::System;
    }
}
