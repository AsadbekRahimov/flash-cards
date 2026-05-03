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
         * Max cards per training session (protects against infinite loops
         * on the client if bugs cause it to never stop requesting next).
         */
        'max_cards_per_session' => (int) env('TWA_MAX_CARDS_PER_SESSION', 100),
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
