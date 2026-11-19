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
];
