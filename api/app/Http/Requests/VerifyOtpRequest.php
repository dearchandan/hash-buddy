<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
            'code' => ['required', 'string', 'size:'.config('hashbuddy.otp.length')],
            'device_name' => ['sometimes', 'string', 'max:60'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->phone)) {
            $this->merge(['phone' => preg_replace('/[\s\-()]/', '', $this->phone)]);
        }
    }
}
