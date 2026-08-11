<?php

namespace Tests\Support;

use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushSender;

/**
 * Records instead of sending, so tests can assert who would have been buzzed
 * without reaching Firebase.
 */
class RecordingPushSender implements PushSender
{
    /** @var array<int, array{user_id: int, message: PushMessage}> */
    public array $sent = [];

    public function send(User $user, PushMessage $message): int
    {
        $this->sent[] = ['user_id' => $user->id, 'message' => $message];

        return 1;
    }

    /** @return array<int, PushMessage> */
    public function to(int $userId): array
    {
        return array_values(array_map(
            static fn (array $row): PushMessage => $row['message'],
            array_filter($this->sent, static fn (array $row): bool => $row['user_id'] === $userId),
        ));
    }

    /** @return array<int, string> */
    public function typesTo(int $userId): array
    {
        return array_map(static fn (PushMessage $m): string => $m->type, $this->to($userId));
    }
}
