<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorisesRideMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\RideGroup;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use AuthorisesRideMembership;

    public function __construct(private readonly ChatService $chat) {}

    /**
     * Chat history, and the polling endpoint: pass ?after=<id> for new messages
     * only, or ?before=<id> to page backwards.
     */
    public function index(Request $request, RideGroup $rideGroup): JsonResponse
    {
        $this->requireMembership($request, $rideGroup);

        $messages = $this->chat->history(
            $rideGroup,
            $request->filled('after') ? $request->integer('after') : null,
            $request->filled('before') ? $request->integer('before') : null,
        );

        // Reading the history is what marks it read; a separate call would just
        // be a second round trip that the client could forget to make.
        if ($messages->isNotEmpty()) {
            $this->chat->markRead($rideGroup, $request->user(), (int) $messages->last()->id);
        }

        return MessageResource::collection($messages)
            ->additional(['meta' => ['poll_seconds' => (int) config('hashbuddy.chat.poll_seconds', 4)]])
            ->response();
    }

    public function store(StoreMessageRequest $request, RideGroup $rideGroup): JsonResponse
    {
        $this->requireMembership($request, $rideGroup);

        $message = $this->chat->send($rideGroup, $request->user(), $request->string('body')->toString());

        // The sender has by definition read their own message.
        $this->chat->markRead($rideGroup, $request->user(), (int) $message->id);

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * Unread counts across every ride, for badges on the ride list.
     */
    public function unread(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->chat->unreadCounts($request->user())]);
    }

    public function markRead(Request $request, RideGroup $rideGroup): JsonResponse
    {
        $this->requireMembership($request, $rideGroup);

        $upTo = $request->filled('message_id')
            ? $request->integer('message_id')
            : (int) Message::where('ride_group_id', $rideGroup->id)->max('id');

        $this->chat->markRead($rideGroup, $request->user(), $upTo);

        return response()->json(['message' => 'Marked read.', 'last_read_message_id' => $upTo]);
    }
}
