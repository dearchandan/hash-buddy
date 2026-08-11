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
        // Blanket "return the code in the response" switch. Honoured only
        // outside production, so leaving it on by mistake on a live server
        // cannot turn login into an open door.
        'debug' => env('HASHBUDDY_OTP_DEBUG', false),

        // Specific numbers that get their code in the API response instead of
        // by SMS, even in production. This is how a small test group signs in
        // before an SMS provider exists, without making every account
        // reachable by anyone who can guess a phone number.
        'test_numbers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('HASHBUDDY_OTP_TEST_NUMBERS', '')),
        ))),

        // 'log' writes the code to the Laravel log and sends nothing. Any other
        // value must be backed by a real integration in OtpService::deliver().
        'sms_driver' => env('HASHBUDDY_SMS_DRIVER', 'log'),

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

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | Group chat opens once travellers share a ride and closes when the ride
    | does. Delivery is a polling cursor rather than a socket: without a live
    | connection to keep alive it costs nothing when nobody is looking, and a
    | few seconds of latency is invisible for "I'm at gate 4, black jacket".
    |
    */

    'chat' => [
        'poll_seconds' => 4,
        'page_size' => 50,
        'max_length' => 1000,
        // Per minute, per traveller. Generous for coordinating a kerb pickup,
        // tight enough that a compromised token cannot flood a stranger.
        'rate_limit_per_minute' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice calls
    |--------------------------------------------------------------------------
    |
    | Peer-to-peer WebRTC: audio never touches the server, and no traveller ever
    | learns another's phone number. STUN alone is not enough — Indian mobile
    | carriers sit behind symmetric NAT, where the only route between two
    | handsets is a TURN relay. Leaving TURN unconfigured does not degrade
    | gracefully; it fails for a large share of real calls.
    |
    */

    'calls' => [
        'enabled' => env('HASHBUDDY_CALLS_ENABLED', true),
        // How long an unanswered call rings before it is marked missed.
        'ring_seconds' => (int) env('HASHBUDDY_CALL_RING_SECONDS', 45),
        // Poll interval while a call is being set up or is ringing.
        'poll_seconds' => 2,

        'stun_urls' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('HASHBUDDY_STUN_URLS', 'stun:stun.l.google.com:19302'),
        )))),

        'turn' => [
            'urls' => array_values(array_filter(array_map('trim', explode(
                ',',
                (string) env('HASHBUDDY_TURN_URLS', ''),
            )))),
            // Long-term credential mechanism (RFC 5389). The shared secret never
            // leaves the server; clients receive a short-lived derived username
            // and password so a leaked APK cannot be used to relay traffic
            // indefinitely.
            'secret' => env('HASHBUDDY_TURN_SECRET'),
            'credential_ttl_seconds' => (int) env('HASHBUDDY_TURN_TTL', 3600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push notifications
    |--------------------------------------------------------------------------
    |
    | Chat without push is close to useless: a message nobody is told about is
    | worse than no message, because the sender believes they made contact.
    | Push also carries call invites, which is why signalling needs no socket.
    |
    | The 'log' driver writes what it would have sent, so the whole feature runs
    | and tests pass before Firebase credentials exist.
    |
    */

    'push' => [
        'driver' => env('HASHBUDDY_PUSH_DRIVER', 'log'),
        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            // Absolute path to the service-account JSON. Kept outside the repo
            // and never committed: it can send push to every install you have.
            'credentials' => env('FCM_CREDENTIALS_PATH'),
        ],
    ],

];
