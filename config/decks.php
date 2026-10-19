<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Learner decks ("Мои колоды")
|--------------------------------------------------------------------------
|
| Reference: docs/13_PHASE2_DESIGN.md §8.1, docs/04 A.10.
| Limits are keyed by the plan the EntitlementResolver reports (Plan::value); they are kept
| apart from config/billing.php on purpose, which describes plans, features and prices.
| `decks` counts a learner's non-archived decks, `deck_words` the ACTIVE words of one deck.
*/

return [
    'limits' => [
        'free' => ['decks' => 1, 'deck_words' => 50],
        'pro' => ['decks' => 20, 'deck_words' => 2000],
    ],

    /** Words per generated lesson ("Часть N") of a deck. */
    'lesson_size' => 50,

    /** Longest deck title, characters. */
    'title_max_length' => 60,

    /** Text import (DeckImportParser). `max_kb` is the request-size cap in kilobytes. */
    'import' => [
        'max_kb' => 256,
        'max_lines' => 500,
    ],
];
