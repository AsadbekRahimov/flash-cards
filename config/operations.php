<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Readiness probe (GET /health/ready)
    |--------------------------------------------------------------------------
    */
    'readiness' => [
        // Scheduler writes a heartbeat every minute; older than this = "fail".
        'heartbeat_max_age_seconds' => (int) env('READINESS_HEARTBEAT_MAX_AGE', 180),

        // Queues whose backlog is checked, and the max allowed size of each.
        'queues' => [(string) env('TELEGRAM_QUEUE', 'high'), 'default'],
        'queue_max_size' => (int) env('READINESS_QUEUE_MAX_SIZE', 1000),
    ],
];
