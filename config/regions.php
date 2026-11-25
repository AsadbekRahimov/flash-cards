<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The region of a learner (docs/35 §2.2, §2.3)
|--------------------------------------------------------------------------
|
| The first-level units of Uzbekistan by ISO 3166-2:UZ (12 regions, the Republic of Karakalpakstan and Tashkent City) plus
| `other` - everybody outside Uzbekistan. The list is stable and is not edited in `/admin`: `learners.region_code` stores the code,
| App\Domain\Learners\Support\Regions reads this file. The names for the learners are lang/{ru,uz}/regions.php; the English ones
| below are for the exports (CSV).
*/

return [
    /** Code => English name; the 14 units of Uzbekistan, then `other`. */
    'codes' => [
        'UZ-TK' => 'Tashkent City',
        'UZ-TO' => 'Tashkent Region',
        'UZ-AN' => 'Andijan Region',
        'UZ-BU' => 'Bukhara Region',
        'UZ-FA' => 'Fergana Region',
        'UZ-JI' => 'Jizzakh Region',
        'UZ-NG' => 'Namangan Region',
        'UZ-NW' => 'Navoiy Region',
        'UZ-QA' => 'Kashkadarya Region',
        'UZ-QR' => 'Republic of Karakalpakstan',
        'UZ-SA' => 'Samarkand Region',
        'UZ-SI' => 'Syrdarya Region',
        'UZ-SU' => 'Surkhandarya Region',
        'UZ-XO' => 'Khorezm Region',
        'other' => 'Outside Uzbekistan',
    ],

    /**
     * The order of the 14 units of Uzbekistan in the pickers, per interface language: Tashkent City first, then by the alphabet of
     * the language (docs/35 §2.3). `other` is not in the lists: it is a separate choice ("Another country").
     */
    'order' => [
        'ru' => ['UZ-TK', 'UZ-AN', 'UZ-BU', 'UZ-JI', 'UZ-QA', 'UZ-NW', 'UZ-NG', 'UZ-QR', 'UZ-SA', 'UZ-SU', 'UZ-SI', 'UZ-TO', 'UZ-FA', 'UZ-XO'],
        'uz' => ['UZ-TK', 'UZ-AN', 'UZ-BU', 'UZ-FA', 'UZ-JI', 'UZ-NG', 'UZ-NW', 'UZ-QA', 'UZ-QR', 'UZ-SA', 'UZ-SI', 'UZ-SU', 'UZ-TO', 'UZ-XO'],
    ],

    /** A change of the region (not the first choice, not a reset) is allowed once in this many days (docs/35 §2.3). */
    'change_days' => 30,

    /** "Not now" on the region card of the Rating tab hides it for this many days (docs/35 §2.3). */
    'prompt_snooze_days' => 30,
];
