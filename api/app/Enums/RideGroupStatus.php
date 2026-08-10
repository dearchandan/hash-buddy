<?php

namespace App\Enums;

enum RideGroupStatus: string
{
    case Forming = 'forming';
    case Locked = 'locked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function acceptsJoins(): bool
    {
        return $this === self::Forming;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
