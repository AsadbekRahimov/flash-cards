<?php

declare(strict_types=1);

/*
 * Polls in the bot (docs/35 §1, O3): the broadcast. One delivery row per learner, sent at most once.
 */
return [
    // Queue of SendPollBatchJob: the bulk queue, so a broadcast never holds the workers of the replies and the reminders.
    'queue' => env('POLLS_QUEUE', 'low'),

    // The broadcast sends only while the WHOLE bot (all background sends and the replies of the second) is below this many
    // messages per second, and not at all for a moment after any other sender was refused a slot (TelegramMessageRateLimiter::
    // reserveGlobalBelow). Capped by TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND. 0 = no ceiling of its own (tests).
    'send_per_second' => (int) env('POLLS_SEND_PER_SECOND', 15),

    // Job chains started at launch. Each sends one message at a time; two keep up with the ceiling above.
    'workers' => (int) env('POLLS_WORKERS', 2),

    // Rows a job leases at once, and how long it sends before it hands on to a fresh job (the worker's timeout is 120 s).
    'batch_size' => (int) env('POLLS_BATCH_SIZE', 100),
    'batch_seconds' => (int) env('POLLS_BATCH_SECONDS', 30),

    // A leased row nobody sent comes back to the other workers after this long (a crashed worker). Longer than a batch.
    'lease_seconds' => (int) env('POLLS_LEASE_SECONDS', 300),

    // `polls:sweep`: a row left in `sending` longer than this becomes `unknown` (never sent again).
    'stuck_minutes' => (int) env('POLLS_STUCK_MINUTES', 15),

    // The broadcast's deadline from the launch: a job is not run after it (`retryUntil`), what is left stays `pending`.
    'broadcast_ttl_hours' => (int) env('POLLS_BROADCAST_TTL_HOURS', 24),

    // Learner ids per INSERT of the launch (a range of ids: 2 000 rows × 7 bindings stay far under PostgreSQL's 65 535).
    'insert_chunk' => (int) env('POLLS_INSERT_CHUNK', 2000),
];
