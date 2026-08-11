<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A rule of the ride domain was broken — the group filled up, the traveller is
 * already aboard, the windows drifted apart. These are expected outcomes of a
 * race between two people tapping "join", not bugs.
 */
class RideException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ride_error',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function groupFull(): self
    {
        return new self('This ride is already full.', 'group_full', 409);
    }

    public static function groupClosed(): self
    {
        return new self('This ride is no longer accepting travellers.', 'group_closed', 409);
    }

    public static function alreadyMember(): self
    {
        return new self('You have already joined this ride.', 'already_member', 409);
    }

    public static function notMember(): self
    {
        return new self('You are not part of this ride.', 'not_member', 404);
    }

    public static function requestNotOpen(): self
    {
        return new self('That ride request is no longer open.', 'request_not_open');
    }

    public static function requestAlreadyGrouped(): self
    {
        return new self('That ride request is already attached to a ride.', 'request_already_grouped');
    }

    public static function destinationMismatch(): self
    {
        return new self('This ride is heading to a different terminal or zone.', 'destination_mismatch');
    }

    public static function windowMismatch(int $minOverlap): self
    {
        return new self(
            "Your departure window needs to overlap this ride by at least {$minOverlap} minutes.",
            'window_mismatch',
        );
    }

    public static function genderPolicy(): self
    {
        return new self('This ride is limited to women travellers.', 'gender_policy', 403);
    }

    public static function preferenceUnmet(): self
    {
        return new self('You asked to travel with women only; this ride is open to everyone.', 'preference_unmet');
    }

    public static function tooManyOpenRequests(int $max): self
    {
        return new self("You can only have {$max} open ride requests at a time.", 'too_many_open_requests');
    }

    public static function userBlocked(): self
    {
        return new self('Your account cannot join rides right now.', 'user_blocked', 403);
    }

    public static function chatClosed(): self
    {
        return new self('This ride is over, so its chat is closed.', 'chat_closed', 409);
    }

    public static function callsDisabled(): self
    {
        return new self('Calling is not available right now.', 'calls_disabled', 503);
    }

    public static function callTargetNotMember(): self
    {
        return new self('That traveller is not on this ride.', 'call_target_not_member', 404);
    }

    public static function callAlreadyLive(): self
    {
        return new self('There is already a call in progress on this ride.', 'call_already_live', 409);
    }

    public static function callNotRinging(): self
    {
        return new self('This call is no longer ringing.', 'call_not_ringing', 409);
    }

    public static function cannotCallYourself(): self
    {
        return new self('You cannot call yourself.', 'cannot_call_yourself');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
        ], $this->status);
    }
}
