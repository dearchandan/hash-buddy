<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCallRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'callee_id' => ['required', 'integer', 'exists:users,id'],
            // A full session description with ICE candidates already gathered.
            // Generous ceiling: SDP grows with every candidate, and a handset
            // on wifi plus mobile data can produce a surprising number.
            'offer_sdp' => ['required', 'string', 'max:65535'],
        ];
    }
}
