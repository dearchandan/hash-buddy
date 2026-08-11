<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'body' => $this->body,
            // Null for system lines. The client renders those centred and
            // unattributed rather than as a message from nobody.
            'sender' => $this->whenLoaded(
                'user',
                fn () => $this->user ? new PublicUserResource($this->user) : null,
                null,
            ),
            'is_mine' => $this->user_id !== null && $this->user_id === $request->user()?->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
