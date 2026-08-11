<?php

namespace App\Push;

use App\Models\User;

interface PushSender
{
    /**
     * Deliver to every device the user has registered.
     *
     * Implementations must not throw: a push that fails is never a reason to
     * fail the request that triggered it. Sending a chat message must succeed
     * even when Firebase is down, or the message is lost along with the
     * notification.
     *
     * @return int Number of devices the message was accepted for.
     */
    public function send(User $user, PushMessage $message): int;
}
