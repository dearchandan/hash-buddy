<?php

namespace App\Push;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Writes what it would have sent. The default until Firebase credentials exist,
 * so chat and calls are fully testable without them — and so a misconfigured
 * production server degrades to "no notifications" rather than 500s on every
 * message.
 */
class LogPushSender implements PushSender
{
    public function send(User $user, PushMessage $message): int
    {
        $devices = $user->deviceTokens()->count();

        Log::info('push.stub', [
            'user_id' => $user->id,
            'devices' => $devices,
            'type' => $message->type,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->payload(),
        ]);

        return $devices;
    }
}
