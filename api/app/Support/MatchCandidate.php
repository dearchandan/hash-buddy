<?php

namespace App\Support;

use App\Models\RideGroup;
use App\Models\RideRequest;

/**
 * One possible travel companion, either an existing group with a free seat or
 * a lone traveller you could form a new group with.
 *
 * `score` ranks candidates against each other. It is not a probability or a
 * percentage of anything real — `overlapMinutes` is the concrete fact behind it
 * and is what the app should show a traveller.
 */
readonly class MatchCandidate
{
    public const TYPE_GROUP = 'group';

    public const TYPE_TRAVELLER = 'traveller';

    public function __construct(
        public string $type,
        public int $score,
        public int $overlapMinutes,
        public FareEstimate $fare,
        public bool $sameFlight,
        public ?RideGroup $group = null,
        public ?RideRequest $rideRequest = null,
    ) {}

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }
}
