<?php

namespace App\Services;

use App\Enums\GenderPolicy;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\RideGroupStatus;
use App\Enums\RideRequestStatus;
use App\Exceptions\RideException;
use App\Models\RideGroup;
use App\Models\RideGroupMember;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RideGroupService
{
    /**
     * Turn an open request into a group and make its owner the host.
     */
    public function createFromRequest(RideRequest $request, ?int $maxSeats = null, ?string $meetingPoint = null): RideGroup
    {
        $this->guardRequestJoinable($request);

        return DB::transaction(function () use ($request, $maxSeats, $meetingPoint) {
            $group = RideGroup::create([
                // Set here rather than left to the model's creating hook, which
                // does not fire when a caller suppresses model events.
                'code' => RideGroup::generateCode(),
                'created_by' => $request->user_id,
                'airport_code' => $request->airport_code,
                'terminal' => $request->terminal,
                'zone_id' => $request->zone_id,
                'window_start' => $request->window_start,
                'window_end' => $request->window_end,
                'max_seats' => $this->clampSeats($maxSeats, $request->seats),
                'gender_policy' => $request->gender_preference,
                'meeting_point' => $meetingPoint,
            ]);

            $this->attach($group, $request, MemberRole::Host);
            $this->lockIfFull($group);

            return $group->refresh()->load(['zone', 'activeMembers.user']);
        });
    }

    /**
     * Seat a traveller in an existing group.
     *
     * Every precondition is re-checked inside the transaction against a locked
     * row, because the interesting failure is two people tapping "join" on the
     * last free seat at the same moment.
     */
    public function join(RideGroup $group, RideRequest $request): RideGroupMember
    {
        $request->loadMissing('user');

        return DB::transaction(function () use ($group, $request) {
            $group = RideGroup::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            // Checked before the request guard: someone tapping join twice is
            // already aboard, and should be told that rather than that their
            // own request is no longer open.
            if ($group->hasMember($request->user_id)) {
                throw RideException::alreadyMember();
            }

            $this->guardRequestJoinable($request);

            if (! $group->status->acceptsJoins()) {
                throw RideException::groupClosed();
            }

            if ($group->airport_code !== $request->airport_code
                || $group->terminal !== $request->terminal
                || $group->zone_id !== $request->zone_id) {
                throw RideException::destinationMismatch();
            }

            if ($group->seatsAvailable() < $request->seats) {
                throw RideException::groupFull();
            }

            $minOverlap = (int) config('hashbuddy.matching.min_overlap_minutes');
            if ($group->overlapMinutesWith($request->window_start, $request->window_end) < $minOverlap) {
                throw RideException::windowMismatch($minOverlap);
            }

            if (! $group->gender_policy->admits($request->user->gender)) {
                throw RideException::genderPolicy();
            }

            if ($request->gender_preference === GenderPolicy::WomenOnly
                && $group->gender_policy !== GenderPolicy::WomenOnly) {
                throw RideException::preferenceUnmet();
            }

            $member = $this->attach($group, $request, MemberRole::Member);

            $this->narrowWindow($group, $request);
            $this->lockIfFull($group);

            return $member;
        });
    }

    /**
     * Give up a seat. The traveller's request goes back on the market rather
     * than being thrown away.
     */
    public function leave(RideGroup $group, User $user): RideGroup
    {
        return DB::transaction(function () use ($group, $user) {
            $group = RideGroup::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            $member = $group->members()
                ->where('user_id', $user->id)
                ->where('status', MemberStatus::Joined)
                ->first();

            if (! $member) {
                throw RideException::notMember();
            }

            $request = $member->rideRequest;

            $member->forceFill([
                'status' => MemberStatus::Left,
                'left_at' => now(),
            ])->save();

            $group->decrement('seats_taken', $member->seats);
            $group->decrement('luggage_total', min($group->luggage_total, $request?->luggage_count ?? 0));

            if ($request && $request->status === RideRequestStatus::Matched) {
                $request->forceFill([
                    'status' => RideRequestStatus::Open,
                    'ride_group_id' => null,
                    'matched_at' => null,
                ])->save();
            }

            $group->refresh();

            if ($group->activeMembers()->count() === 0) {
                $group->forceFill([
                    'status' => RideGroupStatus::Cancelled,
                    'cancelled_at' => now(),
                ])->save();

                return $group;
            }

            if ($member->isHost()) {
                $this->promoteNewHost($group);
            }

            if ($group->status === RideGroupStatus::Locked) {
                $group->forceFill([
                    'status' => RideGroupStatus::Forming,
                    'locked_at' => null,
                ])->save();
            }

            return $group->refresh()->load(['zone', 'activeMembers.user']);
        });
    }

    private function attach(RideGroup $group, RideRequest $request, MemberRole $role): RideGroupMember
    {
        // updateOrCreate rather than create: a traveller who left and came back
        // still collides with the unique (group, user) index.
        $member = RideGroupMember::updateOrCreate(
            ['ride_group_id' => $group->id, 'user_id' => $request->user_id],
            [
                'ride_request_id' => $request->id,
                'role' => $role,
                'status' => MemberStatus::Joined,
                'seats' => $request->seats,
                'joined_at' => now(),
                'left_at' => null,
            ],
        );

        $group->increment('seats_taken', $request->seats);
        $group->increment('luggage_total', $request->luggage_count);

        $request->forceFill([
            'status' => RideRequestStatus::Matched,
            'ride_group_id' => $group->id,
            'matched_at' => now(),
        ])->save();

        return $member;
    }

    /**
     * The group can only depart when everyone aboard is present, so its window
     * shrinks to the intersection of member windows.
     */
    private function narrowWindow(RideGroup $group, RideRequest $request): void
    {
        $group->forceFill([
            'window_start' => $group->window_start->max($request->window_start),
            'window_end' => $group->window_end->min($request->window_end),
        ])->save();
    }

    private function lockIfFull(RideGroup $group): void
    {
        if ($group->refresh()->isFull()) {
            $group->forceFill([
                'status' => RideGroupStatus::Locked,
                'locked_at' => now(),
            ])->save();
        }
    }

    private function promoteNewHost(RideGroup $group): void
    {
        $next = $group->activeMembers()->orderBy('joined_at')->orderBy('id')->first();

        if (! $next) {
            return;
        }

        $next->forceFill(['role' => MemberRole::Host])->save();
        $group->forceFill(['created_by' => $next->user_id])->save();
    }

    private function clampSeats(?int $requested, int $minimum = 1): int
    {
        $default = (int) config('hashbuddy.groups.default_max_seats');
        $max = (int) config('hashbuddy.groups.absolute_max_seats');

        return max($minimum, min($requested ?? $default, $max));
    }

    private function guardRequestJoinable(RideRequest $request): void
    {
        $request->loadMissing('user');

        if ($request->user->isBlocked()) {
            throw RideException::userBlocked();
        }

        if (! $request->isOpen()) {
            throw RideException::requestNotOpen();
        }

        if ($request->ride_group_id !== null) {
            throw RideException::requestAlreadyGrouped();
        }
    }
}
