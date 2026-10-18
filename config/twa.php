<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Web App — JWT & initData validation
    |--------------------------------------------------------------------------
    */

    'jwt' => [
        'secret' => env('TWA_JWT_SECRET', ''),
        'ttl' => (int) env('TWA_JWT_TTL', 900),   // seconds
        'alg' => env('TWA_JWT_ALG', 'HS256'),
        'iss' => env('TWA_JWT_ISS', 'lexiflow'),
    ],

    'init_data' => [
        /**
         * Max age of Telegram initData in seconds.
         * Telegram docs recommend 24h window.
         */
        'max_age' => (int) env('TWA_INIT_DATA_MAX_AGE', 86400),
    ],

    'training' => [
        /**
         * Max graded cards (training_reviews rows) per training session.
         * Protects against infinite loops on the client if bugs cause it to
         * never stop requesting next. With learning steps every step is a
         * review, so a 25-word lesson needs ~75 reviews plus lapses.
         */
        'max_cards_per_session' => (int) env('TWA_MAX_CARDS_PER_SESSION', 200),
    ],

    'tts' => [
        'service_url' => env('TTS_SERVICE_URL', ''),
        'timeout' => (int) env('TTS_SERVICE_TIMEOUT', 8),
    ],

    /**
     * Public base URL of the TWA SPA (served as static files from /twa/).
     * Telegram web_app buttons MUST point to HTTPS. For local ngrok usage
     * set this to the ngrok URL, e.g. https://abc.ngrok-free.app
     */
    'base_url' => env('TWA_BASE_URL', env('APP_URL', 'http://localhost')),
];
