<?php

namespace App\Http\Resources;

use App\Support\MatchCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MatchCandidate
 */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MatchCandidate $candidate */
        $candidate = $this->resource;

        return [
            'type' => $candidate->type,
            'score' => $candidate->score,
            'overlap_minutes' => $candidate->overlapMinutes,
            'same_flight' => $candidate->sameFlight,
            'fare_estimate' => $candidate->fare->toArray(),

            // What the app should offer. An existing group can be joined
            // outright; a lone traveller has no group yet, so the way to reach
            // them is to open one of your own and become visible to them.
            'action' => $candidate->isGroup() ? 'join_group' : 'create_group',

            'group' => $candidate->group
                ? new RideGroupResource($candidate->group)
                : null,
            'traveller' => $candidate->rideRequest
                ? [
                    'ride_request_id' => $candidate->rideRequest->id,
                    'window_start' => $candidate->rideRequest->window_start->toIso8601String(),
                    'window_end' => $candidate->rideRequest->window_end->toIso8601String(),
                    'drop_landmark' => $candidate->rideRequest->drop_landmark,
                    'flight_number' => $candidate->rideRequest->flight_number,
                    'luggage_count' => (int) $candidate->rideRequest->luggage_count,
                    'user' => new PublicUserResource($candidate->rideRequest->user),
                ]
                : null,
        ];
    }
}
