<?php

namespace App\Http\Requests;

use App\Enums\CabService;
use App\Enums\GenderPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRideRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'airport_code' => ['sometimes', 'string', 'size:3'],
            'terminal' => ['required', 'string', Rule::in(['T1', 'T2'])],
            'zone_id' => ['required', 'integer', Rule::exists('zones', 'id')->where('is_active', true)],
            'drop_landmark' => ['nullable', 'string', 'max:120'],
            'flight_number' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]{2,3}[- ]?\d{1,4}$/'],
            'window_start' => ['required', 'date'],
            'window_end' => ['required', 'date', 'after:window_start'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:'.config('hashbuddy.groups.absolute_max_seats')],
            'luggage_count' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'gender_preference' => ['sometimes', Rule::in(GenderPolicy::values())],
            'note' => ['nullable', 'string', 'max:280'],

            // All three optional by design. A traveller who checked Ola before
            // opening the app has a real number to share; one who walked out of
            // arrivals wanting company does not, and a required field would
            // only get an invented figure that the joiner would then trust.
            'quoted_fare' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'cab_service' => ['nullable', Rule::in(CabService::values())],
            'meeting_point' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->date('window_start');
            $end = $this->date('window_end');
            $minutes = $start->diffInMinutes($end);

            $min = config('hashbuddy.requests.min_window_minutes');
            $max = config('hashbuddy.requests.max_window_minutes');

            if ($minutes < $min) {
                $validator->errors()->add('window_end', "Your departure window must be at least {$min} minutes wide so there is room to match.");
            }

            if ($minutes > $max) {
                $validator->errors()->add('window_end', "Your departure window cannot be wider than {$max} minutes.");
            }

            if ($end->isPast()) {
                $validator->errors()->add('window_end', 'That departure window has already passed.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->flight_number)) {
            $this->merge(['flight_number' => strtoupper(str_replace([' ', '-'], '', $this->flight_number))]);
        }
    }
}
