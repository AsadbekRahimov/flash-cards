<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Achievements and share cards (docs/13 §1.5, §7.4 — task 2C.4; the card of docs/36 §2б)
|--------------------------------------------------------------------------
|
| The keys and their thresholds live in App\Domain\Engagement\Enums\AchievementKey.
*/

return [
    /** Master switch: `false` = nothing is unlocked and no card is sent (the list still shows what is held). */
    'enabled' => (bool) env('ACHIEVEMENTS_ENABLED', true),

    /**
     * Disk and directory of the rendered JPEG cards (one file per key, locale and text — see AchievementCardRenderer). The
     * directory is public: nginx serves `/storage/share-cards/` (Telegram downloads the photo of a shared message by its URL).
     */
    'disk' => 'public',
    'directory' => 'share-cards',

    /** Where the cards of template 1 (PNG) lay: `achievements:prune-cards` clears it. */
    'legacy_directory' => 'achievements',

    /** `achievements:prune-cards` (weekly) deletes a card nobody rendered or shared for this many days; it is drawn again on demand. */
    'prune_after_days' => 30,

    /** JPEG quality of the cards (Telegram re-compresses the photo anyway). */
    'jpeg_quality' => 90,

    /**
     * The illustrations of the families (docs/36 §2б, A1): `<family>.png` — a transparent PNG, 960×840 (drawn at 2×). A missing
     * file is not an error: the card shows the medal. A new file changes the name of the cards of its family (they are drawn anew).
     */
    'illustrations' => env('ACHIEVEMENT_CARD_ART_PATH') ?: resource_path('images/cards'),

    /** Noto Sans (SIL OFL 1.1, resources/fonts/OFL.txt): Latin with the Uzbek letters, and Cyrillic. */
    'fonts' => [
        'regular' => resource_path('fonts/NotoSans-Regular.ttf'),
        'bold' => resource_path('fonts/NotoSans-Bold.ttf'),
    ],
];
