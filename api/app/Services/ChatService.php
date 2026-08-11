<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Enums\MessageType;
use App\Enums\RideGroupStatus;
use App\Exceptions\RideException;
use App\Models\Message;
use App\Models\RideGroup;
use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Group chat for travellers who already share a ride.
 *
 * Chat exists to solve one problem — two strangers finding each other at a kerb
 * — so it opens when they are seated together and closes with the ride. It is
 * deliberately not a way to talk to someone you merely matched with.
 */
class ChatService
{
    public function __construct(private readonly PushSender $push) {}

    /**
     * Messages after a cursor, oldest first so the client can append.
     *
     * @return Collection<int, Message>
     */
    public function history(RideGroup $group, ?int $afterId = null, ?int $beforeId = null): Collection
    {
        $limit = (int) config('hashbuddy.chat.page_size', 50);

        $query = $group->messages()->with('user')->limit($limit);

        if ($afterId !== null) {
            // Polling for new messages: take the oldest unseen ones so a gap
            // never opens between what the client holds and what it fetches.
            return $query->where('id', '>', $afterId)->orderBy('id')->get();
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        // First load and back-scroll want the newest, but the client renders
        // oldest-first, so flip the page after taking it.
        return $query->orderByDesc('id')->get()->reverse()->values();
    }

    public function send(RideGroup $group, User $sender, string $body): Message
    {
        $this->guardOpen($group);

        $body = trim($body);

        $message = $group->messages()->create([
            'user_id' => $sender->id,
            'type' => MessageType::Text,
            'body' => $body,
        ]);

        $this->notifyOthers($group, $sender->id, new PushMessage(
            type: 'chat.message',
            data: ['message_id' => (string) $message->id, 'sender_id' => (string) $sender->id],
            title: $sender->name ?: 'Your ride mate',
            // Truncated rather than sent whole: the banner is a prompt to open
            // the app, and a long message would spill onto the lock screen.
            body: Str::limit($body, 120),
            groupId: $group->id,
        ));

        return $message->load('user');
    }

    /**
     * A line the app writes itself — someone joined, a call went unanswered.
     * Never pushed: these narrate something the traveller just did or was
     * already told about, and a second buzz for it is noise.
     */
    public function system(RideGroup $group, string $body): Message
    {
        return $group->messages()->create([
            'user_id' => null,
            'type' => MessageType::System,
            'body' => $body,
        ]);
    }

    /**
     * Unread counts for every ride the traveller is on, so a waiting message is
     * visible from the ride list without opening each one in turn.
     *
     * One grouped query rather than a count per ride: this runs on every list
     * load, and N+1 here would be N+1 on the app's busiest screen.
     *
     * @return array<int, int> group id => unread count
     */
    public function unreadCounts(User $user): array
    {
        return Message::query()
            ->join('ride_group_members as m', function ($join) use ($user): void {
                $join->on('m.ride_group_id', '=', 'messages.ride_group_id')
                    ->where('m.user_id', '=', $user->id)
                    ->where('m.status', '=', MemberStatus::Joined->value);
            })
            ->whereColumn('messages.id', '>', 'm.last_read_message_id')
            // Your own messages are never unread, and a system line does not
            // deserve a badge that implies someone is waiting on a reply.
            ->whereNotNull('messages.user_id')
            ->where('messages.user_id', '!=', $user->id)
            ->groupBy('messages.ride_group_id')
            ->selectRaw('messages.ride_group_id, count(*) as aggregate')
            ->pluck('aggregate', 'messages.ride_group_id')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Advance the traveller's read cursor. Never rewinds: messages arriving
     * between the client's read and this call would otherwise be marked seen.
     */
    public function markRead(RideGroup $group, User $user, int $messageId): void
    {
        $group->members()
            ->where('user_id', $user->id)
            ->where('last_read_message_id', '<', $messageId)
            ->update(['last_read_message_id' => $messageId]);
    }

    /**
     * Push to everyone on the ride except the person who caused it.
     */
    public function notifyOthers(RideGroup $group, int $exceptUserId, PushMessage $message): void
    {
        $group->loadMissing('activeMembers.user');

        foreach ($group->activeMembers as $member) {
            if ($member->user_id === $exceptUserId || $member->user === null) {
                continue;
            }

            $this->push->send($member->user, $message);
        }
    }

    private function guardOpen(RideGroup $group): void
    {
        if (in_array($group->status, [RideGroupStatus::Cancelled, RideGroupStatus::Completed], true)) {
            throw RideException::chatClosed();
        }
    }
}
