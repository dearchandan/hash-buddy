<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'gender' => ['sometimes', Rule::in(Gender::values())],
            'avatar_url' => ['nullable', 'url', 'max:255'],
            'bio' => ['nullable', 'string', 'max:280'],
        ];
    }
}
