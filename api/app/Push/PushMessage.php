<?php

namespace App\Push;

/**
 * One notification, independent of who delivers it.
 *
 * `data` is what the app acts on; `title`/`body` are what a human reads. Call
 * invites carry data only and no title, so a ringing call never also lands in
 * the notification tray as a stale banner after it has been answered.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public string $type,
        public array $data = [],
        public ?string $title = null,
        public ?string $body = null,
        public ?int $groupId = null,
    ) {}

    /**
     * FCM requires every data value to be a string; anything else is rejected
     * at send time rather than at compile time, so it is coerced here once.
     *
     * @return array<string, string>
     */
    public function payload(): array
    {
        $payload = ['type' => $this->type] + $this->data;

        if ($this->groupId !== null) {
            $payload['ride_group_id'] = (string) $this->groupId;
        }

        return array_map(static fn (mixed $value): string => (string) $value, $payload);
    }

    public function isSilent(): bool
    {
        return $this->title === null && $this->body === null;
    }
}
