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
     *   rung 1 — recognition of the meaning: the word is seen, its translation picked among four (`meaning`, graded by
     *            the server from the pick);
     *   rung 2 — recognition of the form with four options (reverse / audio, graded by the server from the pick);
     *   rung 3 — typed recall (spelling / gap-fill) once the interval is long enough.
     * A rung whose exercise is off (config, the learner's setting, the plan, a group lesson) is served one rung lower.
     * A lapse of a `review` card lowers the ceiling by one rung (`word_repetitions.meta.ladder_floor`); the card climbs
     * back with its interval. `enabled = false` restores the previous rotation (docs/06 §4.3), in which `meaning` takes no part.
     */
    'ladder' => [
        'enabled' => (bool) env('LEARNING_LADDER_ENABLED', true),
        /** The meaning exercise (rung 1) at all; `false` serves that rung as flash and the floor after a lapse at the options as flash too. */
        'meaning_enabled' => (bool) env('LEARNING_LADDER_MEANING_ENABLED', true),
        /** From this many passes on (`repetitions`), a `review` card leaves the self-graded flash for the choice of the meaning. */
        'meaning_from_repetitions' => (int) env('LEARNING_LADDER_MEANING_FROM_REPETITIONS', 2),
        /** From this many passes on (`repetitions`), a `review` card is asked for the word: four English words or by ear. */
        'choice_from_repetitions' => (int) env('LEARNING_LADDER_CHOICE_FROM_REPETITIONS', 3),
        /**
         * From this interval (days) on, a `review` card is asked to be typed (spelling / gap-fill). 6 (owner's decision of
         * 2026-11-17, was 21): a word that has survived its first day-based interval is typed at its next review (day ~8 under
         * SM-2); the typo allowance of SpellingGrader and the ladder floor after a lapse soften the step.
         */
        'typed_from_interval_days' => (int) env('LEARNING_LADDER_TYPED_FROM_INTERVAL_DAYS', 6),
        /**
         * Whether a Free learner gets the typed rung. `false` (default): the typed exercises stay a Pro feature
         * (`exercise.spelling` / `exercise.gap_fill` of config/billing.php) and a Free learner's ladder tops out at the four
         * options. The owner's decision (docs/25 §3.2): `true` opens typed recall to everybody.
         */
        'typed_recall_in_free' => (bool) env('LEARNING_LADDER_TYPED_IN_FREE', false),

        /**
         * Objective exercises from the first day (owner's decision of 2026-11-17, docs/06 §13.1). The first meeting with a word
         * stays a flash card (the word, its translation and example are TAUGHT, not asked); every later serve — the minute steps
         * of the same session, the first day-based reviews — is an active recall in a format the server grades, and no word
         * gets the same format twice in a row (`word_repetitions.meta.last_exercise`). Each list is the preference order of a
         * stage, the first entry whose exercise is available (plan, the learner's settings, audio) and differs from the last
         * one served wins; a stage whose only candidate was just served borrows from the stage below, and flash is the
         * universal fallback:
         *   recognition — cards in minute steps (learning / relearning) and `review` cards below `choice_from_repetitions`;
         *   form        — `review` cards from `choice_from_repetitions` on (rung 2);
         *   the typed rung alternates gap-fill and spelling the same way.
         * Group lessons keep the parity ladder (one screen for the class). `enabled = false` restores the ladder as it was:
         * cards in minute steps and the first passes are flash, the upper rungs alternate by parity.
         */
        'early' => [
            'enabled' => (bool) env('LEARNING_LADDER_EARLY_ENABLED', true),
            'recognition' => env('LEARNING_LADDER_EARLY_RECOGNITION', 'meaning,reverse,audio'),
            'form' => env('LEARNING_LADDER_EARLY_FORM', 'reverse,audio,flash'),
            /**
             * Serve the audio exercise only when the word has a pre-generated file (`card.audio_url`): the Mini App falls back
             * to Web Speech, whose voice is not guaranteed on every device, and a silent audio card is unanswerable. The
             * delivered courses have no files yet (`php artisan audio:pregenerate <course>`), so audio waits for them.
             */
            'audio_requires_file' => (bool) env('LEARNING_LADDER_AUDIO_REQUIRES_FILE', true),
        ],
    ],

    /**
     * The sprint (owner's decision of 2026-11-17, docs/06 §13.2): a `daily_review` session ends by itself after a short, fixed
     * portion — at most `due_cards` day-based reviews plus at most `new_words` new words with their learning steps — with
     * `done_reason = sprint_complete` and `can_continue` when another round would have cards. The daily new-word quota of the
     * Free plan is untouched (it is the day's limit; `new_words` is the round's). `enabled = false`: the session runs until
     * nothing is left (or the per-session cap), the progress is "grades / daily goal" as before.
     * A limit of 0 (or less) is NO limit of that kind: the round is bounded by the other one alone (a round that could hold
     * no card of a kind would end empty with "one more round" on offer, forever).
     * `debt_holds_new`: while more day-based reviews are due than one round takes (`due_cards`), the round introduces no new
     * words — the backlog is cleared first, round by round, as new words only came after the due ones before the sprint;
     * a newcomer and a learner with no backlog are not affected.
     */
    'sprint' => [
        'enabled' => (bool) env('LEARNING_SPRINT_ENABLED', true),
        'new_words' => (int) env('LEARNING_SPRINT_NEW_WORDS', 6),
        'due_cards' => (int) env('LEARNING_SPRINT_DUE_CARDS', 15),
        'debt_holds_new' => (bool) env('LEARNING_SPRINT_DEBT_HOLDS_NEW', true),
    ],

    /**
     * The round of hard words (`hard_review`, docs/06 §14): an occasional round of the words the learner stumbles on - `words` of
     * them at most, offered when at least `min_words` qualify, `per_day` times a local day. A word qualifies when it was released
     * (`review` / `relearning`), is hard (`is_hard`) or failed `lapses_from` times or more, and was not graded today. The answers
     * are real repetitions (SM-2 / FSRS untouched); the words are brought to "due now" when the round opens. `enabled = false`:
     * no block on "Today", no offer after a session, opening is refused (a round already open is played on).
     * `due_within_days`: a candidate's term is due or comes within this many days - the round never pulls a word more than that
     * ahead of its schedule, otherwise SM-2 would inflate its interval (docs/06 §14); 0 = overdue words only.
     */
    'hard_round' => [
        'enabled' => (bool) env('LEARNING_HARD_ROUND_ENABLED', true),
        'words' => (int) env('LEARNING_HARD_ROUND_WORDS', 10),
        'min_words' => (int) env('LEARNING_HARD_ROUND_MIN_WORDS', 6),
        'per_day' => (int) env('LEARNING_HARD_ROUND_PER_DAY', 1),
        'lapses_from' => (int) env('LEARNING_HARD_ROUND_LAPSES_FROM', 2),
        'due_within_days' => (int) env('LEARNING_HARD_ROUND_DUE_WITHIN_DAYS', 2),
    ],

    /**
     * The review of a lesson just studied (`lesson_review`, docs/04 A.3, docs/06 §15; the step 2 "Тренировка" of the day plan, docs/31 §5б):
     * a practice round over the words of ONE lesson that the learner met for the first time TODAY (their `word_repetitions` row was
     * born in the local day and graded at least once) - at most `max_words` of them, each served until answered right, at most
     * `max_serves_per_word` times. Objective exercises only (meaning / reverse / audio). It never moves the schedule (SM-2 / FSRS,
     * `word_repetitions` untouched), writes no `training_reviews` and no day statistics: no league points, no daily goal, no streak,
     * no Free quota - so playing it again gives nothing either. `enabled = false`: opening is refused (`disabled`), the plan has no step 2.
     */
    'lesson_review' => [
        'enabled' => (bool) env('LEARNING_LESSON_REVIEW_ENABLED', true),
        'max_words' => (int) env('LEARNING_LESSON_REVIEW_MAX_WORDS', 20),
        'max_serves_per_word' => (int) env('LEARNING_LESSON_REVIEW_MAX_SERVES', 3),
    ],

    /**
     * The stages of a word as the learner sees them (docs/06 §13.3), from the row of `word_repetitions`:
     *   new      — met, not graduated yet (state new / learning);
     *   learning — graduated, interval below `known_from_interval_days` (and a lapsed card in relearning);
     *   known    — interval from `known_from_interval_days` on;
     *   mastered — interval from `mastered_from_interval_days` on.
     * The session summary counts the words that climbed a stage during the session.
     * `fresh_review_days` splits the `learning` stage for the "memory strength" 0–5 of the Mini App (docs/40 §2.2, docs/06 §13.3a,
     * MemoryStrength): a graduated card below it is strength 2, from it up to `known_from_interval_days` strength 3.
     */
    'stages' => [
        'fresh_review_days' => (int) env('LEARNING_STAGE_FRESH_REVIEW_DAYS', 3),
        'known_from_interval_days' => (int) env('LEARNING_STAGE_KNOWN_FROM_INTERVAL_DAYS', 7),
        'mastered_from_interval_days' => (int) env('LEARNING_STAGE_MASTERED_FROM_INTERVAL_DAYS', 21),
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

        /**
         * Break in learning (owner decision 2026-11-16, docs/12 §7.5). `idle` = local days since
         * the last day with reviews. From `winback_from_days` the text becomes "N days without
         * lessons"; up to `daily_until_days` a reminder goes out every day, after that only on
         * the `sparse_days`; beyond `stop_after_days` the bot stays silent (a learner who left
         * for good would otherwise block it). Each is read as at least 1; the list is
         * filtered to positive integers.
         */
        'winback_from_days' => max(1, (int) env('REMINDERS_WINBACK_FROM_DAYS', 3)),
        'daily_until_days' => max(1, (int) env('REMINDERS_DAILY_UNTIL_DAYS', 7)),
        'sparse_days' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('REMINDERS_SPARSE_DAYS', '10,14,21,30,45,60'))),
            static fn (int $day): bool => $day > 0,
        )),
        'stop_after_days' => max(1, (int) env('REMINDERS_STOP_AFTER_DAYS', 60)),

        /**
         * The reminder as a question with four buttons (owner decision 2026-11-17, docs/12 §7.5). `enabled` = false brings
         * back the plain text with one button. A question only goes to the first `max_idle_days` of a break (the rarer
         * reminders of the sparse days stay text); `ttl_hours` = how long a question can be answered (the state lives in
         * the non-evictable Redis, docs/19 §2.2); `candidates` = how many cards are tried to build four options for.
         */
        'quiz' => [
            'enabled' => (bool) env('REMINDERS_QUIZ_ENABLED', true),
            'max_idle_days' => max(0, (int) env('REMINDERS_QUIZ_MAX_IDLE_DAYS', 7)),
            'ttl_hours' => max(1, (int) env('REMINDERS_QUIZ_TTL_HOURS', 26)),
            'candidates' => 3,

            /**
             * The question vs the plain text among quiz-eligible reminders (audit 2026-11-18, «Причина вернуться завтра» /
             * «Напоминание в Telegram»; the owner's switch). While `enabled`, a learner whose reminder may be a question is put
             * for good into the `quiz` arm when `crc32("salt:learner_id") % 100 < share`, otherwise into `plain` (no
             * question is even built); both arms keep the same time and frequency. `starts_at` (UTC date or instant, optional):
             * the experiment does not split before it, and it is the default `--from` of `analytics:reminder-experiment`.
             * A new `salt` reshuffles everybody — change it only for a new experiment. Off = exactly the behaviour without it.
             */
            'experiment' => [
                'enabled' => (bool) env('REMINDERS_QUIZ_EXPERIMENT', false),
                'share' => min(100, max(0, (int) env('REMINDERS_QUIZ_EXPERIMENT_SHARE', 50))),
                'salt' => (string) env('REMINDERS_QUIZ_EXPERIMENT_SALT', 'reminder-quiz-2026-11'),
                'starts_at' => env('REMINDERS_QUIZ_EXPERIMENT_STARTS_AT'),
            ],
        ],

        /**
         * The reminder after a lesson (docs/31 §5б, LessonNudgeScheduler / LessonNudgeSender): `delay_minutes` after the `/finish` of
         * a lesson with new words, one message "fix the words in a game" with the game of the lesson - if by then no game on its
         * words was played. Once a local day, inside the common limit of one message about studies a day, not to those who switched
         * the reminders off. Not at night: at a local hour from `quiet_from_hour` to `quiet_to_hour` (exclusive, may wrap midnight)
         * it is dropped, not moved to the morning. `queue`: the queue of the job.
         */
        'lesson_nudge' => [
            'enabled' => (bool) env('REMINDERS_LESSON_NUDGE_ENABLED', true),
            'delay_minutes' => max(1, (int) env('REMINDERS_LESSON_NUDGE_DELAY_MINUTES', 45)),
            'queue' => (string) env('REMINDERS_LESSON_NUDGE_QUEUE', 'default'),
            'quiet_from_hour' => 22,
            'quiet_to_hour' => 8,
        ],
    ],
];
