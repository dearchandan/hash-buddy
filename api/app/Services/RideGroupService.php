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
use App\Push\PushMessage;
use App\Push\PushSender;
use Illuminate\Support\Facades\DB;

class RideGroupService
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly PushSender $push,
    ) {}

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
                // The fare and the spot describe the cab, not the traveller, so
                // they move onto the ride from whichever request opened it.
                // An explicit meeting point argument still wins, since that is
                // a caller deliberately overriding what the request carried.
                'meeting_point' => $meetingPoint ?? $request->meeting_point,
                'quoted_fare' => $request->quoted_fare,
                'cab_service' => $request->cab_service,
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

            $this->announceJoin($group, $request->user);

            return $member;
        });
    }

    /**
     * Take a seat in a ride you found by browsing, without describing a trip
     * the ride has already described.
     *
     * The whole point of the browse flow: everything except how many of you
     * there are and how much you are carrying is already known — the airport,
     * the terminal, the destination, the departure window all come from the
     * ride itself. Deriving the window from the group rather than asking for
     * one also means the overlap check can never fail, because by tapping join
     * you have agreed to their window.
     *
     * Wrapped in its own transaction so a join that loses the race for the last
     * seat does not leave an orphaned open request behind.
     */
    public function quickJoin(
        RideGroup $group,
        User $user,
        int $seats = 1,
        int $luggage = 1,
        ?string $flightNumber = null,
    ): RideGroupMember {
        if ($user->isBlocked()) {
            throw RideException::userBlocked();
        }

        return DB::transaction(function () use ($group, $user, $seats, $luggage, $flightNumber) {
            $request = RideRequest::create([
                'user_id' => $user->id,
                'airport_code' => $group->airport_code,
                'terminal' => $group->terminal,
                'zone_id' => $group->zone_id,
                'window_start' => $group->window_start,
                'window_end' => $group->window_end,
                'seats' => $seats,
                'luggage_count' => $luggage,
                'flight_number' => $flightNumber,
                // Not the joiner's to set. They are choosing this specific ride,
                // so a preference of their own would only contradict it.
                'gender_preference' => GenderPolicy::Any,
                // Belongs to this join, not to the traveller. See leave().
                'is_derived' => true,
            ]);

            $request->setRelation('user', $user);

            return $this->join($group, $request);
        });
    }

    /**
     * Tell the people already aboard that someone took a seat.
     *
     * This is the notification that makes the product work. Without it a lone
     * traveller only discovers they have been matched by opening the app and
     * looking again, which nobody does while walking through an airport.
     */
    private function announceJoin(RideGroup $group, ?User $joiner): void
    {
        $name = $joiner?->name ?: 'Someone';

        $this->chat->system($group, "{$name} joined the ride.");

        $this->chat->notifyOthers($group, $joiner?->id ?? 0, new PushMessage(
            type: 'ride.joined',
            data: ['user_id' => (string) ($joiner?->id ?? 0)],
            title: 'You have a ride mate',
            body: "{$name} is sharing your cab. Say hello and agree where to meet.",
            groupId: $group->id,
        ));
    }

    /**
     * Close a ride you opened, for everyone.
     *
     * Host-only, because the host is the person who was going to book the cab —
     * if they pull out, the ride genuinely is over rather than merely one seat
     * lighter. Everyone else's request goes back on the market so they can find
     * another, and they are told rather than left waiting at a kerb.
     */
    public function cancel(RideGroup $group, User $user): RideGroup
    {
        // Collected inside the transaction, before the members are released:
        // afterwards activeMembers is empty by design, and notifying from it
        // would reach nobody precisely when it matters most.
        [$group, $strandedIds] = DB::transaction(function () use ($group, $user) {
            $group = RideGroup::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            // State before permission. Closing releases every member including
            // the host, so a second attempt would otherwise fail the host check
            // first and report "you are not the host" about a ride the caller
            // just closed themselves.
            $this->guardOpen($group);
            $this->guardHost($group, $user);

            $members = $group->activeMembers()->with('rideRequest')->get();
            $stranded = $members->where('user_id', '!=', $user->id)->pluck('user_id')->all();

            foreach ($members as $member) {
                $this->release($member, reopen: $member->user_id !== $user->id);
            }

            $group->forceFill([
                'status' => RideGroupStatus::Cancelled,
                'cancelled_at' => now(),
                'seats_taken' => 0,
                'luggage_total' => 0,
            ])->save();

            return [$group, $stranded];
        });

        $this->chat->system($group, ($user->name ?: 'The host').' closed this ride.');

        $this->notify($strandedIds, new PushMessage(
            type: 'ride.cancelled',
            title: 'Your ride was closed',
            body: ($user->name ?: 'The host').' closed the ride. Your request is looking for mates again.',
            groupId: $group->id,
        ));

        return $group->load(['zone']);
    }

    /**
     * Mark the ride done — you are in the cab and leaving.
     *
     * Capacity is clamped to who actually turned up, which is the whole point
     * of being able to complete an unfilled ride: a two-seater that leaves with
     * two people splits the fare two ways, not three, and nobody can join a cab
     * that has already gone.
     */
    public function complete(RideGroup $group, User $user): RideGroup
    {
        [$group, $riderIds] = DB::transaction(function () use ($group, $user) {
            $group = RideGroup::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            $this->guardOpen($group);
            $this->guardHost($group, $user);

            if ($group->seats_taken < 1) {
                throw RideException::rideEmpty();
            }

            $members = $group->activeMembers()->with('rideRequest')->get();

            foreach ($members as $member) {
                $member->rideRequest?->forceFill([
                    'status' => RideRequestStatus::Completed,
                ])->save();
            }

            $group->forceFill([
                'status' => RideGroupStatus::Completed,
                'completed_at' => now(),
                // Freezes the split at the people who actually came, and closes
                // the ride to anyone still browsing it. A two-seater that
                // leaves with two people splits two ways, not three.
                'max_seats' => $group->seats_taken,
                'locked_at' => $group->locked_at ?? now(),
            ])->save();

            return [$group, $members->where('user_id', '!=', $user->id)->pluck('user_id')->all()];
        });

        $this->chat->system($group, 'Ride completed. Settle up between you.');

        $this->notify($riderIds, new PushMessage(
            type: 'ride.completed',
            title: 'Ride completed',
            body: $group->quoted_fare === null
                ? 'Safe travels. Settle the fare between you.'
                : 'Your share is about '.$group->fareShare().' rupees.',
            groupId: $group->id,
        ));

        return $group->load(['zone', 'activeMembers.user']);
    }

    private function guardOpen(RideGroup $group): void
    {
        if (in_array($group->status, [RideGroupStatus::Cancelled, RideGroupStatus::Completed], true)) {
            throw RideException::rideAlreadyClosed();
        }
    }

    private function guardHost(RideGroup $group, User $user): void
    {
        $isHost = $group->members()
            ->where('user_id', $user->id)
            ->where('status', MemberStatus::Joined)
            ->where('role', MemberRole::Host)
            ->exists();

        if (! $isHost) {
            throw RideException::hostOnly();
        }
    }

    /**
     * Take a member off a ride, optionally putting their request back on the
     * market. Derived requests are always retired — see leave().
     */
    private function release(RideGroupMember $member, bool $reopen): void
    {
        $member->forceFill([
            'status' => MemberStatus::Left,
            'left_at' => now(),
        ])->save();

        $request = $member->rideRequest;

        if ($request === null || $request->status !== RideRequestStatus::Matched) {
            return;
        }

        $request->forceFill($reopen && ! $request->is_derived
            ? ['status' => RideRequestStatus::Open, 'ride_group_id' => null, 'matched_at' => null]
            : [
                'status' => RideRequestStatus::Cancelled,
                'ride_group_id' => null,
                'matched_at' => null,
                'cancelled_at' => now(),
            ])->save();
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function notify(array $userIds, PushMessage $message): void
    {
        foreach (User::whereKey($userIds)->get() as $user) {
            $this->push->send($user, $message);
        }
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
                // A request the traveller typed is an instruction — find me a
                // ride — so it goes back on the market. One derived to take a
                // seat while browsing is an implementation detail of that one
                // join, and reopening it would leave them advertising a trip
                // they never described.
                $request->forceFill($request->is_derived
                    ? [
                        'status' => RideRequestStatus::Cancelled,
                        'ride_group_id' => null,
                        'matched_at' => null,
                        'cancelled_at' => now(),
                    ]
                    : [
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
