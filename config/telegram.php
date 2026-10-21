<?php

declare(strict_types=1);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),

    'url_secret' => env('TELEGRAM_URL_SECRET', ''),
    'header_secret' => env('TELEGRAM_HEADER_SECRET', ''),

    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),

    'queue' => env('TELEGRAM_QUEUE', 'high'),

    'outbound_rate_limit' => [
        'max_messages' => (int) env('TELEGRAM_OUTBOUND_MAX_PER_MINUTE', 20),
        'window_seconds' => 60,
    ],

    // Bot-wide ceiling of background sends (reminders, assignments, DMs of centers/offers, leaderboards)
    // per second: Bot API allows ~30 msg/s per bot, the rest is left for replies to updates. 0 = off. docs/13 §9 (2R.3).
    'outbound' => [
        'global_per_second' => (int) env('TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND', 25),

        // How long a console command (assignments:refresh) waits for a free slot before it postpones the send.
        'sync_wait_ms' => 2000,
    ],

    // Incoming updates per sender (docs/12 §8): over the limit they are dropped without a reply.
    'inbound_rate_limit' => [
        'max_messages' => (int) env('TELEGRAM_INBOUND_MAX_PER_MINUTE', 30),
        'window_seconds' => 60,
    ],

    'ip_allowlist' => [
        'enabled' => (bool) env('TELEGRAM_IP_ALLOWLIST_ENABLED', false),
        'cidrs' => ['149.154.160.0/20', '91.108.4.0/22'],
    ],

    'logging' => [
        'channel' => env('TELEGRAM_LOG_CHANNEL', 'telegram'),
    ],
];
