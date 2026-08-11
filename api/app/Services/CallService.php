<?php

namespace App\Services;

use App\Enums\CallStatus;
use App\Exceptions\RideException;
use App\Models\CallSession;
use App\Models\RideGroup;
use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushSender;
use Illuminate\Support\Facades\DB;

/**
 * Signalling for peer-to-peer voice calls between two travellers on the same
 * ride.
 *
 * The server never carries audio. It brokers one offer and one answer, then
 * gets out of the way — so a call costs the same as two API requests, and no
 * traveller ever learns another's phone number.
 */
class CallService
{
    public function __construct(
        private readonly PushSender $push,
        private readonly ChatService $chat,
    ) {}

    /**
     * Ring another traveller. The caller has already gathered ICE candidates,
     * so the offer it passes is complete and needs no follow-up.
     */
    public function start(RideGroup $group, User $caller, int $calleeId, string $offerSdp): CallSession
    {
        if (! config('hashbuddy.calls.enabled')) {
            throw RideException::callsDisabled();
        }

        if ($calleeId === $caller->id) {
            throw RideException::cannotCallYourself();
        }

        if (! $group->hasMember($calleeId)) {
            throw RideException::callTargetNotMember();
        }

        // Serialised against a concurrent start: two people tapping call at the
        // same moment must not each believe theirs is the live one.
        return DB::transaction(function () use ($group, $caller, $calleeId, $offerSdp): CallSession {
            $this->expireStale($group);

            $live = $group->calls()->live()->lockForUpdate()->first();

            if ($live !== null) {
                throw RideException::callAlreadyLive();
            }

            $call = $group->calls()->create([
                'caller_id' => $caller->id,
                'callee_id' => $calleeId,
                'status' => CallStatus::Ringing,
                'offer_sdp' => $offerSdp,
            ]);

            $callee = User::find($calleeId);

            if ($callee !== null) {
                // Data-only and high priority: the app raises its own incoming
                // call screen. A notification banner would still be sitting in
                // the tray after the call was answered or missed.
                $this->push->send($callee, new PushMessage(
                    type: 'call.incoming',
                    data: [
                        'call_id' => (string) $call->id,
                        'caller_id' => (string) $caller->id,
                        'caller_name' => $caller->name ?: 'Your ride mate',
                    ],
                    groupId: $group->id,
                ));
            }

            return $call;
        });
    }

    /**
     * Accept a ringing call, returning the answer to the caller's poll.
     */
    public function accept(CallSession $call, User $callee, string $answerSdp): CallSession
    {
        return DB::transaction(function () use ($call, $callee, $answerSdp): CallSession {
            $fresh = CallSession::whereKey($call->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== CallStatus::Ringing || $fresh->callee_id !== $callee->id) {
                throw RideException::callNotRinging();
            }

            $fresh->forceFill([
                'status' => CallStatus::Accepted,
                'answer_sdp' => $answerSdp,
                'answered_at' => now(),
            ])->save();

            return $fresh;
        });
    }

    public function decline(CallSession $call, User $user): CallSession
    {
        return $this->finish($call, $user, CallStatus::Declined, 'declined');
    }

    public function hangUp(CallSession $call, User $user): CallSession
    {
        return $this->finish($call, $user, CallStatus::Ended, 'hung_up');
    }

    /**
     * What the other party polls for: the current state of the call, including
     * the answer once it exists.
     */
    public function current(RideGroup $group, User $user): ?CallSession
    {
        $this->expireStale($group);

        return $group->calls()
            ->live()
            ->where(fn ($query) => $query->where('caller_id', $user->id)->orWhere('callee_id', $user->id))
            ->latest('id')
            ->first();
    }

    /**
     * ICE servers for the client, with short-lived TURN credentials.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array
    {
        $servers = [];

        foreach ((array) config('hashbuddy.calls.stun_urls', []) as $url) {
            $servers[] = ['urls' => $url];
        }

        $turnUrls = (array) config('hashbuddy.calls.turn.urls', []);
        $secret = config('hashbuddy.calls.turn.secret');

        if ($turnUrls !== [] && is_string($secret) && $secret !== '') {
            // coturn's REST credential scheme: the username is an expiry
            // timestamp and the password is its HMAC. The shared secret stays
            // on the server, so credentials lifted from a phone stop working
            // within the hour instead of relaying someone else's traffic
            // forever.
            $expiry = time() + (int) config('hashbuddy.calls.turn.credential_ttl_seconds', 3600);
            $username = (string) $expiry;
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));

            $servers[] = [
                'urls' => array_values($turnUrls),
                'username' => $username,
                'credential' => $credential,
            ];
        }

        return $servers;
    }

    private function finish(CallSession $call, User $user, CallStatus $status, string $reason): CallSession
    {
        if (! $call->involves($user->id)) {
            throw RideException::notMember();
        }

        if ($call->status->isFinal()) {
            return $call;
        }

        $call->forceFill([
            'status' => $status,
            'ended_at' => now(),
            'end_reason' => $reason,
        ])->save();

        $this->push->send(
            User::findOrFail($call->otherParty($user->id)),
            new PushMessage(
                type: 'call.ended',
                data: ['call_id' => (string) $call->id, 'reason' => $reason],
                groupId: $call->ride_group_id,
            ),
        );

        // A missed call is the one thing worth leaving behind in the chat: it
        // is the only signal that someone tried to reach you and could not.
        if ($status === CallStatus::Missed) {
            $caller = User::find($call->caller_id);
            $this->chat->system(
                $call->group,
                ($caller?->name ?: 'A traveller').' tried to call.',
            );
        }

        return $call;
    }

    /**
     * Close out calls that rang unanswered.
     *
     * Done on read rather than on a schedule: a stale ringing row only matters
     * when someone looks, and this keeps the feature free of a queue worker.
     */
    private function expireStale(RideGroup $group): void
    {
        $cutoff = now()->subSeconds((int) config('hashbuddy.calls.ring_seconds', 45));

        $stale = $group->calls()
            ->where('status', CallStatus::Ringing)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $call) {
            $call->forceFill([
                'status' => CallStatus::Missed,
                'ended_at' => now(),
                'end_reason' => 'no_answer',
            ])->save();

            $caller = User::find($call->caller_id);
            $this->chat->system(
                $group,
                ($caller?->name ?: 'A traveller').' tried to call.',
            );
        }
    }
}
