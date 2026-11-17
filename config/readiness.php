<?php

declare(strict_types=1);

/*
 * Readiness to the level and the quiz of the week (docs/13 §8.5, docs/04 A.20).
 *
 * The percent is the share of the course's active words the learner recalls after a week or more (the stages of
 * `config/learning.php` → `stages.*`, docs/06 §13.3); the quiz is a separate figure beside it and does not change the
 * percent. Off (`enabled = false`): `/today` carries `readiness: null`, the screen and the quiz answer 404.
 */
return [
    'enabled' => (bool) env('READINESS_ENABLED', true),

    'quiz' => [
        // false: the quiz is off (404 `level_quiz_disabled`), the readiness itself stays.
        'enabled' => (bool) env('READINESS_QUIZ_ENABLED', true),
        // Read as max(MIN_QUESTIONS, min(30, n)).
        'questions' => (int) env('READINESS_QUIZ_QUESTIONS', 12),
        // One timer for the whole quiz; read as at least 60.
        'seconds' => (int) env('READINESS_QUIZ_SECONDS', 600),
        // The share of the questions taken from the words the learner has met (0..1); the rest are words not met yet.
        'met_share' => (float) env('READINESS_QUIZ_MET_SHARE', 0.67),
        // Weeks of the history on the screen, the current one included.
        'history_weeks' => (int) env('READINESS_QUIZ_HISTORY_WEEKS', 12),
    ],
];
