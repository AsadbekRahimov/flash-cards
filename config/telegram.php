<?php

declare(strict_types=1);

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),

    'url_secret' => env('TELEGRAM_URL_SECRET', ''),
    'header_secret' => env('TELEGRAM_HEADER_SECRET', ''),

    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),

    'queue' => env('TELEGRAM_QUEUE', 'high'),

    'ip_allowlist' => [
        'enabled' => (bool) env('TELEGRAM_IP_ALLOWLIST_ENABLED', false),
        'cidrs' => ['149.154.160.0/20', '91.108.4.0/22'],
    ],

    'logging' => [
        'channel' => env('TELEGRAM_LOG_CHANNEL', 'telegram'),
    ],
];
