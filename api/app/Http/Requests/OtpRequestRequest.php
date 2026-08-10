<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtpRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // E.164, defaulting to Indian mobile numbers.
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $this->phone)]);
        }
    }
}
