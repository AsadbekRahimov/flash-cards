<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Practice (four solo modes) and the 1-on-1 battle (docs/16 §3.1)
|--------------------------------------------------------------------------
|
| The keys are final (docs/16): `env()` is read only by the switches and the Free limits. `access`: off | pilot | all;
| `plan`: all | pro. A switched-off mode / the battle blocks only the CREATION of a session / an invitation:
| sessions that are open and matches that are on go on to their end (docs/16 §3.2).
|
| `free_limits` (docs/18 §3, G1): what a learner WITHOUT the entitlement feature `practice.unlimited` (Pro, sponsored Pro
| included) may create per local day - solo sessions opened (a retry of the mistakes is free) and battles called (a rematch
| counts for the one who offers it; accepting is always free). `0` = only Pro creates; empty / null = no limit for Free.
| Refused: 403 `practice_limit_reached` (docs/16 §3.2). `plan = pro` keeps the older rule: Free gets 403 `practice_pro_required`.
*/

return [
    'access' => env('PRACTICE_ACCESS', 'off'),          // off | pilot | all
    'plan' => env('PRACTICE_PLAN', 'all'),              // all | pro
    'free_limits' => [
        'sessions_per_day' => env('PRACTICE_FREE_SESSIONS_PER_DAY', 2),
        'battles_per_day' => env('PRACTICE_FREE_BATTLES_PER_DAY', 1),
    ],
    'modes' => [
        'pairs' => (bool) env('PRACTICE_MODE_PAIRS', true),
        'collocation' => (bool) env('PRACTICE_MODE_COLLOCATION', true),
        'sentence' => (bool) env('PRACTICE_MODE_SENTENCE', true),
        'dictation' => (bool) env('PRACTICE_MODE_DICTATION', true),
    ],
    'battle' => [
        'enabled' => (bool) env('PRACTICE_BATTLE', true),
        'rounds' => 5, 'pairs_per_round' => 4,
        'round_seconds' => ['collocation' => 30, 'pairs' => 45, 'sentence' => 45, 'dictation' => 60],
        'countdown_seconds' => 3, 'reveal_seconds' => 4,
        'invite_ttl_seconds' => 300, 'room_ttl_seconds' => 120,
        // The silence that ends a match. The environment shortens it for the e2e stand (docs/16 §14.4), but never below the floor of
        // BattleSettings::presenceGraceFloorSeconds() = heartbeat_write_seconds + 10 s (the longest pause of the poll): the mark of a
        // player who is here is up to 7 s old, up to 15 s after a failed poll, so a shorter grace would cancel healthy matches.
        'presence_grace_seconds' => (int) env('PRACTICE_BATTLE_PRESENCE_GRACE', 30),
        'presence_warn_seconds' => 10, 'heartbeat_write_seconds' => 5, 'deadline_grace_ms' => 1500,
        'points' => ['round' => 100, 'pair' => 25],
        'finished_list' => 10,
    ],
    'solo' => [
        'session_ttl_hours' => 24,
        'items' => ['size' => 5, 'min' => 3],
        'pairs' => ['rounds' => 3, 'per_round' => 4, 'min_per_round' => 3],
        'dictation' => ['max_replacements' => 2, 'phrases_max' => 3],
    ],
    'content' => [
        'sentence_fragments' => ['min' => 3, 'max' => 8], 'sentence_accepted_max' => 6, 'fragment_max_length' => 40,
        'collocation_options' => 3, 'collocation_text_max' => 160,
        'dictation_text_max' => 80, 'dictation_accepted_max' => 5,
        'key_pattern' => '/\A[a-z0-9][a-z0-9._-]{0,63}\z/',
        'explanation_max' => 300, 'prompt_max' => 200,
    ],
    'grading' => [
        'typo_min_length' => 5,
        'protected_tokens' => ['not', 'no', 'never', 'none', 'nothing', 'nobody',
            'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
            'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety', 'hundred', 'thousand', 'million',
            'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth', 'eleventh', 'twelfth', 'thirteenth', 'fourteenth',
            'fifteenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth', 'twentieth', 'thirtieth', 'fortieth', 'fiftieth', 'sixtieth', 'seventieth',
            'eightieth', 'ninetieth', 'hundredth', 'thousandth', 'millionth', 'half'],
    ],
    // How many words (of a whole course) the hub and the options of a battle look at to tell a mode has material: the counts of
    // `available` of an available mode are "at least" this much (a shortage is always counted over every word). Far above the
    // biggest `required` of a mode (battle: rounds x pairs_per_round = 20; solo: 3 words, 5 tasks), so a sample of fit words is ample.
    'count_sample' => 300,
    // How many words a session / a battle on a WHOLE course is built from when the learner has started (a battle: the course has) more:
    // a sample of this size chosen by the seed of the snapshot, so opening costs the same at 300 and at 8000 words (no lesson, module or
    // retry: those are small and read whole). It covers the biggest session many times over: pairs 5 rounds x 4 = 20 words (solo 3 x 4 = 12),
    // a dictation 5 elements + 8 spares = 13, tasks read no words. A session the sample cannot build is built again from all the words.
    // 0 = off.
    'play_sample' => 300,
    'import' => ['max_bytes' => 2 * 1024 * 1024, 'max_items' => 300],
    // config/filesystems.php: root storage/app/practice-audio, no url - the files are served only by the audio endpoint (docs/16 §10.5).
    'audio' => ['disk' => 'practice_audio', 'dir' => ''],
];
