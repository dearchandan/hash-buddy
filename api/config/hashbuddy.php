<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Phone OTP login
    |--------------------------------------------------------------------------
    |
    | There is no SMS provider wired up yet. While `debug` is true the generated
    | code is returned in the API response so the app can be driven end to end
    | locally. This must be false anywhere real users can reach it.
    |
    */

    'otp' => [
        'debug' => env('HASHBUDDY_OTP_DEBUG', false),
        'length' => 6,
        'ttl_minutes' => (int) env('HASHBUDDY_OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('HASHBUDDY_OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Matching
    |--------------------------------------------------------------------------
    |
    | Two travellers match when they leave the same terminal for the same zone
    | and their departure windows overlap by at least `min_overlap_minutes`.
    |
    */

    'matching' => [
        'min_overlap_minutes' => (int) env('HASHBUDDY_MATCH_MIN_OVERLAP_MINUTES', 10),
        'max_results' => (int) env('HASHBUDDY_MATCH_MAX_RESULTS', 20),

        // Relative weights of the compatibility score. Must total 100.
        'weights' => [
            'overlap' => 50,
            'luggage_fit' => 20,
            'rating' => 15,
            'flight_match' => 15,
        ],

        // Overlap in minutes that earns the full overlap weight.
        'ideal_overlap_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ride requests
    |--------------------------------------------------------------------------
    */

    'requests' => [
        'ttl_hours' => (int) env('HASHBUDDY_REQUEST_TTL_HOURS', 12),
        'max_open_per_user' => 3,
        'min_window_minutes' => 15,
        'max_window_minutes' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    |
    | Default capacity is 2. A pair fits a sedan with airport luggage and only
    | needs one other traveller to exist, so pairs both save more per head and
    | match far more often than groups of three.
    |
    */

    'groups' => [
        'default_max_seats' => 2,
        'absolute_max_seats' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fare estimation
    |--------------------------------------------------------------------------
    |
    | Three travellers with airport luggage do not fit the same vehicle class as
    | one, so the estimate upgrades to an SUV past these thresholds rather than
    | pretending a solo fare divides evenly.
    |
    */

    'vehicle' => [
        'sedan_max_passengers' => 2,
        'sedan_max_luggage' => 3,
    ],

];
