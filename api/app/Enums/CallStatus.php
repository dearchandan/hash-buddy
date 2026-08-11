<?php

namespace App\Enums;

enum CallStatus: string
{
    case Ringing = 'ringing';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Ended = 'ended';

    /** Rang out without an answer — distinguished from a deliberate decline. */
    case Missed = 'missed';

    /** Nothing more will happen on this call. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Declined, self::Ended, self::Missed], true);
    }
}
