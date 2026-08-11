<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.config('hashbuddy.chat.max_length', 1000)],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Type something first.',
        ];
    }
}
