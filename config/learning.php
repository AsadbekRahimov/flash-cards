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

    /*
    |--------------------------------------------------------------------------
    | FSRS-5 as an A/B alternative to SM-2 (docs/06 §9, docs/13 §8.3)
    |--------------------------------------------------------------------------
    |
    | `learners.scheduler` is fixed when the learner is created: the bucket
    | `crc32(telegram_user_id) % 100 < rollout_percent` gets `fsrs`, everyone
    | else `sm2`. Changing the percentage never moves an existing learner
    | (an admin can switch one learner in Filament, with an audit record).
    | Rollback: FSRS_ROLLOUT_PERCENT=0 and `UPDATE learners SET scheduler = 'sm2'`;
    | the SM-2 columns are kept intact by FSRS (it writes interval_days /
    | next_review_at too and never touches easiness_factor).
    */
    'fsrs' => [
        /** Share (0..100) of NEW learners that gets the FSRS bucket. 0 — nobody (default). */
        'rollout_percent' => (int) env('FSRS_ROLLOUT_PERCENT', 0),

        /** Target probability of recall at the moment the card comes due (0 < r < 1). */
        'desired_retention' => (float) env('FSRS_DESIRED_RETENTION', 0.9),

        /**
         * FSRS-5 default parameters w[0..18] (comma-separated in env to override).
         * Source: open-spaced-repetition, FSRS-5 defaults — fsrs-rs `DEFAULT_PARAMETERS`
         * (v1.x, 2024-09) / ts-fsrs 4.x `default_w` / fsrs4anki wiki "The Algorithm" (FSRS-5):
         *   w0..w3   initial stability for Again / Hard / Good / Easy
         *   w4, w5   initial difficulty
         *   w6, w7   difficulty delta and mean reversion
         *   w8..w10  stability after a successful review
         *   w11..w14 stability after a lapse
         *   w15, w16 Hard / Easy multipliers
         *   w17, w18 same-day (short-term) reviews — unused here: minute steps are shared with SM-2
         */
        'weights' => env('FSRS_WEIGHTS', '0.40255,1.18385,3.173,15.69105,7.1949,0.5345,1.4604,0.0046,1.54575,0.1192,1.01925,1.9395,0.11,0.29605,2.2698,0.2315,2.9898,0.51655,0.6621'),
    ],

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
         * Safety floor between any two reminders (also across a local midnight or after a
         * change of the reminder time). The main rule is "at most one reminder per local day
         * of the learner" (docs/12 §7.5). Read as at least 1.
         */
        'min_interval_hours' => (int) env('REMINDERS_MIN_INTERVAL_HOURS', 2),

        /**
         * A claimed reminder older than this (scheduler was down, queue lagged,
         * `dm_blocked_at` was cleared weeks later) is not sent: a reminder at a
         * random hour is spam. `next_reminder_at` still moves to the next day.
         * Read as at least 15 (the dispatcher's own cadence and the 14-minute spread cap).
         */
        'max_delay_minutes' => (int) env('REMINDERS_MAX_DELAY_MINUTES', 120),

        /** Learners claimed per SELECT; the backfill of missing `next_reminder_at` is capped per run. */
        'batch_size' => (int) env('REMINDERS_BATCH_SIZE', 200),
        'backfill_limit' => (int) env('REMINDERS_BACKFILL_LIMIT', 10000),

        /**
         * Outgoing reminders per second (Telegram: ~30 messages/s per bot). The dispatcher
         * delays the n-th job of a run by floor(n / rate) seconds, at most 14 minutes
         * and a run claims at most rate × 840 learners (the rest stays due for the next run).
         * All background sends also share the bot-wide `telegram.outbound.global_per_second` budget.
         * Read as at least 1.
         */
        'send_rate_per_second' => (int) env('REMINDERS_SEND_RATE_PER_SECOND', 25),
    ],
];
