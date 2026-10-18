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
     * Interval (days) assigned when the student answers "Easy" (q=5) on a card
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
     * has elapsed is considered abandoned (the student left the session). Such
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
];
