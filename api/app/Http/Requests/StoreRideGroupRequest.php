<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRideGroupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ride_request_id' => ['required', 'integer', 'exists:ride_requests,id'],
            'max_seats' => ['sometimes', 'integer', 'min:2', 'max:'.config('hashbuddy.groups.absolute_max_seats')],
            'meeting_point' => ['nullable', 'string', 'max:255'],
        ];
    }
}
