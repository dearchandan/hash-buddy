<?php

namespace App\Enums;

enum RideRequestStatus: string
{
    case Open = 'open';
    case Matched = 'matched';

    /** The ride happened. Distinct from cancelled, which means it did not. */
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isLive(): bool
    {
        return $this === self::Open || $this === self::Matched;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
