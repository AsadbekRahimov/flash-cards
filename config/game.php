<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The game "Caravan" (docs/18 §12.7.1)
|--------------------------------------------------------------------------
|
| The keys are final (docs/18 §12): `env()` is read only by the switch and the limits. `access`: off | pilot | all; `pilot` is
| the pilot of the practice (`learners.practice_pilot_at`: one audience for both), anything but `all` / `pilot` is `off`.
| There is no `plan = pro` mode: the free part of the game is the product decision of docs/18 §3.
|
| `free_limits` (what a learner WITHOUT the feature says): `runs_per_day` - free map runs (stop + boss) per local day, more cost
| a ticket (feature `game.unlimited_runs` lifts it); `boss_stages` - the bosses of the first N cities of a course (by
| `stages.number`; feature `game.all_bosses`); `boosters_per_week` - boosters used per local week (`pro_limits.boosters_per_week`
| with `game.boosters_plus`). `0` = only Pro; empty / null = no limit for Free. The daily caravan has no limit at all.
*/

return [
    'access' => env('GAME_ACCESS', 'off'),                              // off | pilot | all
    'free_limits' => [
        'runs_per_day' => env('GAME_FREE_RUNS_PER_DAY', 1),
        'boss_stages' => env('GAME_FREE_BOSS_STAGES', 1),
        'boosters_per_week' => env('GAME_FREE_BOOSTERS_PER_WEEK', 1),
    ],
    'pro_limits' => ['boosters_per_week' => env('GAME_PRO_BOOSTERS_PER_WEEK', 5)],
    'tickets' => [
        'per_streak_week' => env('GAME_TICKETS_PER_STREAK_WEEK', 1),    // +1 per 7 days of the streak (streak_current % 7 == 0)
        'per_referral' => env('GAME_TICKETS_PER_REFERRAL', 3),          // to the inviter, for an activated friend
        'max' => env('GAME_TICKETS_MAX', 10),                           // the cap of the balance; the rest is not granted (event game.ticket_capped)
    ],
    'run' => [
        'size_min' => 8, 'size_max' => 10, 'min_elements' => 6, 'authored_max' => 3, 'pairs_rounds_max' => 1, 'pairs_per_round' => 4,
        'flasks' => 3, 'flasks_max_with_booster' => 4, 'ttl_minutes' => 30, 'distractor_pool' => 40,
    ],
    'scoring' => ['correct' => 10, 'typo' => 5, 'combo_from' => 3, 'combo_bonus' => 5, 'combo_bonus_max' => 15],
    'events' => ['chance' => 0.6, 'bazaar_hint_coins' => 5],
    'boss' => ['size' => 12, 'min_words' => 8, 'min_cleared_stops' => 3, 'required_accuracy' => 0.8],
    'economy' => ['stop_first' => 10, 'per_new_star' => 5, 'stop_repeat' => 2, 'daily' => 10, 'daily_per_star' => 5, 'boss_first' => 50, 'boss_repeat' => 5, 'class_boss' => 20],
    'league' => ['correct' => 2, 'answer' => 1, 'daily_cap' => env('GAME_LEAGUE_DAILY_CAP', 40)],
    'class' => ['days' => 7, 'stars_per_member' => 6, 'min_target' => 12, 'centers_only' => (bool) env('GAME_CLASS_CENTERS_ONLY', true)],
    'map' => ['friends_max' => 20],
];
