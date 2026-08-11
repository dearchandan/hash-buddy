<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CallSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->id;

        return [
            'id' => $this->id,
            'ride_group_id' => $this->ride_group_id,
            'status' => $this->status->value,
            'caller_id' => $this->caller_id,
            'callee_id' => $this->callee_id,
            'is_caller' => $this->caller_id === $userId,

            // Each side only ever needs the other's description, and shipping
            // both would hand a third party enough to impersonate either end if
            // a token ever leaked.
            'offer_sdp' => $this->when($this->callee_id === $userId, $this->offer_sdp),
            'answer_sdp' => $this->when($this->caller_id === $userId, $this->answer_sdp),

            'end_reason' => $this->end_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
