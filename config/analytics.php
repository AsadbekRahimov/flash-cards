<?php

declare(strict_types=1);

/*
 * Product analytics collected from the Mini App itself (docs/12 §7.7, docs/04 A.21).
 *
 * `client_timing`: how fast the first open is for the learner (audit 2026-11-18, «Как увидеть выигрыш»). The share of opens
 * (0..100) that report their timing marks; the rate travels to the client in `POST /api/twa/auth` (`client_timing.sample_percent`).
 * 0 switches the collection off on both sides: the client sends nothing and the server records nothing.
 */
return [
    'client_timing' => [
        'sample_percent' => (int) env('CLIENT_TIMING_SAMPLE_PERCENT', 10),
    ],

    /*
     * «Требуют внимания» of the dashboard of `/admin` (docs/ADMIN_GUIDE.md «Главная панель»): the thresholds of its lines, in whole
     * days. `center_wait_days` — an active center is listed once it has waited this long since its creation for the first
     * assignment a learner completed; `reminder_read_after_days` — from this day of the reminder experiment its report is worth
     * reading (the experiment runs 2-3 weeks). No env: change them here, they are not secrets and not per-server.
     */
    'attention' => [
        'center_wait_days' => 7,
        'reminder_read_after_days' => 14,
    ],
];
