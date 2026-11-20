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

    /**
     * Requests refused by TwaAuth (no / bad / expired token, unknown or blocked learner) per IP per
     * minute. TwaAuth runs before the per-learner throttles, so this is the only brake on garbage tokens;
     * above it the answer is 429 `rate_limited`. Valid requests are not counted.
     */
    'unauthorized_per_minute' => (int) env('TWA_UNAUTHORIZED_PER_MINUTE', 60),

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
        /**
         * The speech synthesizer (docs/26 §5.1, bound in AppServiceProvider): `google` - Google Cloud Text-to-Speech
         * (`google` below); `http` - the edge-tts compatible service of `service_url`; `null` - nothing configured, the
         * audio jobs fail with `tts_service_not_configured`. Unset: `http` when `service_url` is set, else `null` (decided
         * when the synthesizer is resolved, so installations without TTS_DRIVER keep working as before).
         */
        'driver' => env('TTS_DRIVER'),
        'service_url' => env('TTS_SERVICE_URL', ''),
        'timeout' => (int) env('TTS_SERVICE_TIMEOUT', 8),

        /**
         * The `google` driver (docs/26 §9.1). `credentials` is the PATH of the service-account key file
         * (e.g. /run/secrets/google-tts.json), never its JSON: `config:cache` would write the private key into
         * bootstrap/cache. `timeout` - seconds for the answer (the connect timeout is a constant of 5 s).
         */
        'google' => [
            'credentials' => (string) env('GOOGLE_TTS_CREDENTIALS', ''),
            'timeout' => (int) env('GOOGLE_TTS_TIMEOUT', 20),
        ],

        /**
         * The `google` driver only (docs/26 §9.3): its pace across all workers (a job waits, up to 30 s) and the characters
         * it may send in one billing month (Pacific time; the free tier is 1 000 000; `0` forbids synthesis).
         */
        'rate_per_minute' => (int) env('TTS_RATE_PER_MINUTE', 150),
        'monthly_char_limit' => (int) env('TTS_MONTHLY_CHAR_LIMIT', 900000),

        /**
         * Pre-generated word audio (docs/12 task 1.2c): `audio/{sha1(text|voice|version)}.mp3`
         * on the `public` disk. Bump `audio_version` to re-voice the whole library
         * (new hash -> new files; `php artisan audio:pregenerate <course>`).
         */
        'voice' => env('TTS_VOICE', 'en-US-AriaNeural'),
        'audio_version' => (int) env('TTS_AUDIO_VERSION', 1),
        /**
         * The disk of the word audio (docs/26 §5.4): `public` - this server, `/storage/audio/` (the default: local and
         * the tests); `audio` - Cloudflare R2 (config/filesystems.php, AUDIO_*). Switched without a code change.
         */
        'audio_disk' => env('TTS_AUDIO_DISK', 'public'),
        'audio_dir' => 'audio',
    ],

    /**
     * Public base URL of the TWA SPA (served as static files from /twa/).
     * Telegram web_app buttons MUST point to HTTPS. For local ngrok usage
     * set this to the ngrok URL, e.g. https://abc.ngrok-free.app
     */
    'base_url' => env('TWA_BASE_URL', env('APP_URL', 'http://localhost')),
];
