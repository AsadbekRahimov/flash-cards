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

        /**
         * The learner's choice of the target retention (docs/25 §3.7; docs/06 §12): `learners.meta.retention_mode`
         * → desired retention. Applies only to learners scheduled by FSRS; `normal` (= `desired_retention`) when unset.
         */
        'retention_modes' => [
            'calm' => 0.85,
            'normal' => (float) env('FSRS_DESIRED_RETENTION', 0.9),
            'intense' => 0.93,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Method: the exercise ladder, objective grading, leeches, hooks, examples,
    | confusable words, the pretest (docs/25 §3; docs/06 §12)
    |--------------------------------------------------------------------------
    */

    /**
     * The ladder of exercises by the maturity of the card (docs/25 §3.1) instead of the hash rotation:
     *   rung 0 — flash (self-graded): cards in minute steps and the first passes after graduation;
     *   rung 1 — recognition with four options (reverse / audio, graded by the server from the pick);
     *   rung 2 — typed recall (spelling / gap-fill) once the interval is long enough.
     * A lapse of a `review` card lowers the ceiling by one rung (`word_repetitions.meta.ladder_floor`); the card climbs
     * back with its interval. `enabled = false` restores the previous rotation (docs/06 §4.3).
     */
    'ladder' => [
        'enabled' => (bool) env('LEARNING_LADDER_ENABLED', true),
        /** From this many passes on (`repetitions`), a `review` card leaves the self-graded flash for the four options. */
        'choice_from_repetitions' => (int) env('LEARNING_LADDER_CHOICE_FROM_REPETITIONS', 3),
        /** From this interval (days) on, a `review` card is asked to be typed (spelling / gap-fill). */
        'typed_from_interval_days' => (int) env('LEARNING_LADDER_TYPED_FROM_INTERVAL_DAYS', 21),
        /**
         * Whether a Free learner gets the typed rung. `false` (default): the typed exercises stay a Pro feature
         * (`exercise.spelling` / `exercise.gap_fill` of config/billing.php) and a Free learner's ladder tops out at the four
         * options. The owner's decision (docs/25 §3.2): `true` opens typed recall to everybody.
         */
        'typed_recall_in_free' => (bool) env('LEARNING_LADDER_TYPED_IN_FREE', false),
    ],

    /**
     * Objective grading (docs/25 §3.2): a four-option card (reverse / audio) is graded by the SERVER from the picked
     * option sent as `answer`, like the typed exercises; only the flash card takes the learner's own `quality`.
     * `accept_legacy_quality`: a client that still sends `quality` for a four-option card is accepted while the new
     * Mini App rolls out — the value is normalised to 4 (pass) / 2 (fail), never 5. Set to false afterwards:
     * such a request is then refused with 422 `quality_not_accepted`.
     */
    'objective_grading' => [
        'accept_legacy_quality' => (bool) env('LEARNING_ACCEPT_LEGACY_CHOICE_QUALITY', true),
    ],

    /**
     * Leeches (docs/25 §3.3): a card with at least this many lapses is a "hard word" — the card carries `is_leech`, the
     * memory hook, the pair it is confused with, and the Mini App shows the chip. 0 = never.
     */
    'leech' => [
        'threshold' => (int) env('LEARNING_LEECH_THRESHOLD', 4),
    ],

    /** Memory hooks (docs/25 §3.4): the authored hook per locale and the learner's own note on the card. */
    'hooks' => [
        'enabled' => (bool) env('LEARNING_HOOKS_ENABLED', true),
        /** Characters of the learner's own note (`word_repetitions.note`); plain text. */
        'note_max_length' => 200,
    ],

    /**
     * Several examples per word (docs/25 §3.5): until the card graduates the anchor example (`words.example`) is the
     * only one shown; after it the examples of `word_examples` rotate with the pass count. `false` — always the anchor.
     */
    'examples' => [
        'rotate' => (bool) env('LEARNING_EXAMPLES_ROTATE', true),
    ],

    /**
     * Confusable words (docs/25 §3.6): `words.meta.confusable_with` lists the words (of the same course) a word is mixed up
     * with. CardPicker does not introduce a word while one of its pairs was introduced to the learner within `window_hours`;
     * the word moves to the end of the portion / the next day. Group lessons are not affected.
     */
    'confusables' => [
        'enabled' => (bool) env('LEARNING_CONFUSABLES_ENABLED', true),
        'window_hours' => (int) env('LEARNING_CONFUSABLES_WINDOW_HOURS', 20),
        /** How many candidates the picker looks ahead for an unblocked word before it serves the first one anyway. */
        'lookahead' => 5,
    ],

    /**
     * The pretest of a new word (docs/25 §3.12): before the first show, "Do you know this word?" with four meanings. A right
     * pick graduates the card as "knew it" (quality 5); a wrong pick or "I don't know" starts the usual learning steps
     * (quality 2) and the Mini App shows the word with its translation. Off by default: the Mini App has just switched to
     * showing a new word with its translation at once.
     */
    'pretest' => [
        'enabled' => (bool) env('LEARNING_PRETEST_ENABLED', false),
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
    | Personal mock exams — IELTS / SAT vocabulary simulator (docs/13 §8.4, Pro)
    |--------------------------------------------------------------------------
    */
    'mock_exam' => [
        /** Courses whose `exam_tag` offers a mock. */
        'exam_tags' => ['ielts', 'sat'],
        /** Questions per mock: random active words of the course, seeded by the session id. */
        'questions' => (int) env('MOCK_EXAM_QUESTIONS', 40),
        /** Whole-exam time limit (seconds); there is no per-question limit. */
        'seconds' => (int) env('MOCK_EXAM_SECONDS', 1200),
        /** The percentile compares with closed mocks of the same course over this many days. */
        'percentile_days' => (int) env('MOCK_EXAM_PERCENTILE_DAYS', 90),
        /** Cache TTL (seconds) of the per-course distribution the percentile is read from. */
        'percentile_cache_ttl' => (int) env('MOCK_EXAM_PERCENTILE_CACHE_TTL', 3600),
    ],

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
