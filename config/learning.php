<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Spaced repetition scheduling (SM-2 + Anki-style learning steps)
|--------------------------------------------------------------------------
|
| Reference: docs/06_SPACED_REPETITION.md
|
| Steps are comma-separated minutes in env, e.g. LEARNING_STEPS="1,10".
| A card graduates to day-based SM-2 intervals after passing the last step.
*/

return [
    'algorithm' => env('LEARNING_ALGORITHM', 'sm2'),

    /**
     * Intra-session steps (minutes) for a card that has never graduated
     * (state `new` → `learning`). Default: 1 min → 10 min → graduate.
     */
    'learning_steps' => env('LEARNING_STEPS', '1,10'),

    /**
     * Intra-session steps (minutes) after a lapse of a graduated card
     * (state `review` → `relearning`). Default: 10 min → graduate.
     */
    'relearning_steps' => env('RELEARNING_STEPS', '10'),

    /**
     * Interval (days) assigned when a card passes its last (re)learning step.
     * Matches SM-2 first interval, so the next pass yields 6 days.
     */
    'graduating_interval_days' => (int) env('LEARNING_GRADUATING_INTERVAL_DAYS', 1),

    /**
     * Interval (days) assigned when the learner answers "Easy" (q=5) on a card
     * that is still in (re)learning: it skips the remaining steps.
     */
    'easy_interval_days' => (int) env('LEARNING_EASY_INTERVAL_DAYS', 4),

    /**
     * Learn-ahead window (minutes). When nothing else is due and there are no
     * new cards, the picker serves the earliest (re)learning card whose
     * next_review_at is within this window, so the session is not cut short.
     * Must be >= the largest learning/relearning step to guarantee in-session
     * completion of all steps.
     */
    'learn_ahead_minutes' => (int) env('LEARNING_LEARN_AHEAD_MINUTES', 20),

    /**
     * After how many hours an unanswered (re)learning card whose minute step
     * has elapsed is considered abandoned (the learner left the session). Such
     * cards count as due for review reminders and `words_due_today`, together
     * with graduated cards (docs/06 §6). Fresh in-session cards are not.
     */
    'abandoned_after_hours' => (int) env('LEARNING_ABANDONED_AFTER_HOURS', 12),

    /**
     * Ceiling (days) for any scheduled interval. SM-2 grows intervals
     * exponentially; `word_repetitions.interval_days` is a smallint, so the
     * value must stay within 1..32767. Default: 10 years.
     */
    'max_interval_days' => (int) env('LEARNING_MAX_INTERVAL_DAYS', 3650),

    /**
     * Personal sessions (`lesson`, `daily_review`) expire this many hours after
     * they were opened: `expires_at = started_at + ttl`, and `training:close-expired`
     * closes them with `done_reason = expired` (docs/12 §7.6).
     */
    'session_ttl_hours' => (int) env('LEARNING_SESSION_TTL_HOURS', 12),

    /**
     * Group sessions (`group_lesson`) have no `expires_at`; the same scheduler
     * closes those started more than this many hours ago. The teacher can always
     * re-open the lesson (`/start_training` is idempotent).
     */
    'group_session_ttl_hours' => (int) env('LEARNING_GROUP_SESSION_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Personal reminders (docs/06 §6, docs/12 §7.5)
    |--------------------------------------------------------------------------
    |
    | `reminders:dispatch` runs every 15 minutes and claims learners whose
    | `next_reminder_at` has passed; the job then decides what to say.
    */
    'reminders' => [
        /**
         * Minimum due cards for a "N words to review" reminder. Below it the job
         * falls back to the streak warning (when the streak is at risk) or stays silent.
         * B2C: a single due word is a valid reason to come back (was 5).
         */
        'due_threshold' => max(1, (int) env('REMINDERS_DUE_THRESHOLD', 1)),

        /**
         * At most one reminder of any kind per learner within this window —
         * the second idempotency level, in `notifications_log`, for retried jobs
         * and overlapping claims.
         */
        'min_interval_hours' => (int) env('REMINDERS_MIN_INTERVAL_HOURS', 20),

        /**
         * A claimed reminder older than this (scheduler was down, queue lagged,
         * `dm_blocked_at` was cleared weeks later) is not sent: a reminder at a
         * random hour is spam. `next_reminder_at` still moves to the next day.
         */
        'max_delay_minutes' => (int) env('REMINDERS_MAX_DELAY_MINUTES', 120),

        /** Learners claimed per SELECT; the backfill of missing `next_reminder_at` is capped per run. */
        'batch_size' => (int) env('REMINDERS_BATCH_SIZE', 200),
        'backfill_limit' => (int) env('REMINDERS_BACKFILL_LIMIT', 1000),
    ],
];
