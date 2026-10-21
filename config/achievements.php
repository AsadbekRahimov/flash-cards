<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Achievements and share cards (docs/13 §1.5, §7.4 — task 2C.4)
|--------------------------------------------------------------------------
|
| The keys and their thresholds live in App\Domain\Engagement\Enums\AchievementKey.
*/

return [
    /** Master switch: `false` = nothing is unlocked and no card is sent (the list still shows what is held). */
    'enabled' => (bool) env('ACHIEVEMENTS_ENABLED', true),

    /** Disk and directory of the rendered PNG cards (one file per key, locale and text — see AchievementCardRenderer). */
    'disk' => 'public',
    'directory' => 'achievements',

    /** Noto Sans (SIL OFL 1.1, resources/fonts/OFL.txt): Latin with the Uzbek letters, and Cyrillic. */
    'fonts' => [
        'regular' => resource_path('fonts/NotoSans-Regular.ttf'),
        'bold' => resource_path('fonts/NotoSans-Bold.ttf'),
    ],
];
