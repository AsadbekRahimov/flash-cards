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

    /** Values of `daily_goal` a learner can choose (settings, docs/12 §7.4); the default above must be one of them. */
    'daily_goal_options' => [10, 20, 40],

    /**
     * Time zones offered in the onboarding / `/settings` picker (IANA ids, the default first;
     * each needs a `onboarding.options.timezone.<city>` string in lang/{ru,uz}). Only ids that
     * are also valid for PHP (`DateTimeZone::listIdentifiers()`) are ever accepted.
     */
    'timezone_options' => [
        'Asia/Tashkent',
        'Asia/Almaty',
        'Asia/Bishkek',
        'Asia/Dushanbe',
        'Asia/Ashgabat',
        'Europe/Moscow',
        'Europe/Istanbul',
    ],
];
