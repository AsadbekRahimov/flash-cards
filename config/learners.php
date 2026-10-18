<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Learner defaults
|--------------------------------------------------------------------------
|
| Reference: docs/12_PHASE1_DESIGN.md §1.2.
*/

return [
    /** IANA timezone assigned to a new learner until onboarding changes it. */
    'default_timezone' => env('LEARNERS_DEFAULT_TIMEZONE', 'Asia/Tashkent'),

    /** Local reminder time (HH:MM) of a new learner; NULL in the column = reminders off. */
    'default_reminder_time' => env('LEARNERS_DEFAULT_REMINDER_TIME', '19:00'),

    /** Daily goal in reviewed cards (options in the UI: 10 / 20 / 40). */
    'default_daily_goal' => (int) env('LEARNERS_DEFAULT_DAILY_GOAL', 20),
];
