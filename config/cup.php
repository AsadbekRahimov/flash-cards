<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The friends' cup of a Telegram group (docs/33, release 5)
|--------------------------------------------------------------------------
|
| `access` switches the whole feature: `off` (default - nothing of the cup is offered or accepted), `pilot` (only the groups an
| administrator of the platform marked with `telegram_groups.meta.cup_pilot = true`), `all`. Sizes are read when a cup is
| PUBLISHED and frozen in its row (`days`, `counted_days`, `round_tasks`, `duel_tasks`): a change here never alters a running cup.
*/

$level = static fn (string $key): ?string => is_string($slug = env($key)) && $slug !== '' ? $slug : null;

return [
    'access' => env('CUP_ACCESS', 'off'),

    // Stage 2 (docs/33 D1-D3): duels on top of the battles. Not built yet; stays false.
    'duels' => (bool) env('CUP_DUELS', false),

    // One timezone for the whole cup: every participant shares the borders of the days.
    'timezone' => env('CUP_TIMEZONE', 'Asia/Tashkent'),

    'levels' => ['A1', 'A2', 'B1', 'B2', 'C1'],
    'default_level' => 'A2',

    // Level => course slug. Null: the first published public course of the platform of that level by `position`.
    'level_courses' => [
        'A1' => $level('CUP_COURSE_A1'),
        'A2' => $level('CUP_COURSE_A2'),
        'B1' => $level('CUP_COURSE_B1'),
        'B2' => $level('CUP_COURSE_B2'),
        'C1' => $level('CUP_COURSE_C1'),
    ],

    'days' => 7,
    'counted_days' => 5,

    'round' => [
        'tasks' => 10,
        'minutes' => 10,
        // An answer is taken until expires_at + grace (as the deadline grace of a battle).
        'grace_ms' => 1500,
        // Words planned per day above `tasks`: a word the composer cannot turn into a task is replaced by a spare one.
        'spare_words' => 2,
    ],

    'duel' => [
        'tasks' => 5,
    ],

    // The finish waits this long after the end of the last day (an attempt of 10 minutes started at 23:59, a duel).
    'settle_minutes' => 30,
    'draft_ttl_hours' => 48,
    // The first day starts at the nearest local midnight at least this far away.
    'start_min_lead_hours' => 6,
    // A published cup that never got two participants is canceled this long after its publication.
    'no_players_cancel_days' => 7,
    // The snapshot of the next day is built this long before the day begins.
    'snapshot_lead_minutes' => 60,
    // The minute of the local day of the summary (20:00).
    'summary_minute' => 1200,

    'membership' => [
        // A fresh getChatMember answer is reused for this long by the same pair (cup, learner) - the throttle of docs/33 §4.2.
        'fresh_seconds' => 10,
        // A "not a member" answer to a VIEW is remembered this long (docs/33 §4.2).
        'negative_seconds' => 30,
        // Telegram unreachable at the start of a round: a participant checked successfully within this window may play.
        'stale_ok_hours' => 24,
        // How long a second request waits for the check of the first one before it gets 429.
        'wait_seconds' => 5,
    ],

    // The table of a cup in the pure cache (docs/19 §3): version in the key, TTL, dropped by every write of the scores.
    'standings_ttl' => 120,

    // cups:advance: cups per step and run.
    'advance_batch' => 200,
];
