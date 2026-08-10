<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinRideGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ride_request_id' => ['required', 'integer', 'exists:ride_requests,id'],
        ];
    }
}
