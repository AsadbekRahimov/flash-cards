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

        // Queues that must show a worker heartbeat (QueueHeartbeatJob, every minute) no older than
        // the limit below. `high`, `default` and `low` each have a worker of their own (docs: worker split).
        // Skipped for sync/null queue drivers (no worker exists there).
        // `tts` (the audio jobs, docs/26 §9.3) is in neither list on purpose: the `low` worker takes it only when `low`
        // is empty (`--queue=low,tts`), so a mass run of thousands of syntheses neither delays the `low` heartbeat nor
        // fails readiness by its backlog.
        'heartbeat_queues' => [(string) env('TELEGRAM_QUEUE', 'high'), 'low'],
        'queue_heartbeat_max_age_seconds' => (int) env('READINESS_QUEUE_HEARTBEAT_MAX_AGE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product analytics events (product_events)
    |--------------------------------------------------------------------------
    */
    'product_events' => [
        // Older events are removed daily by `model:prune` (see routes/console.php).
        'retention_days' => (int) env('PRODUCT_EVENTS_RETENTION_DAYS', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification log (notifications_log) — reminder idempotency and history
    |--------------------------------------------------------------------------
    */
    'notifications_log' => [
        // Older rows are removed daily by `model:prune` (see routes/console.php).
        'retention_days' => (int) env('NOTIFICATIONS_LOG_RETENTION_DAYS', 400),
    ],
];
