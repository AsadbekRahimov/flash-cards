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
