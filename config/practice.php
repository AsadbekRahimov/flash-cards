<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Practice (four solo modes) and the 1-on-1 battle (docs/16 §3.1)
|--------------------------------------------------------------------------
|
| The keys are final (docs/16): `env()` is read only by the switches. `access`: off | pilot | all;
| `plan`: all | pro. A switched-off mode / the battle blocks only the CREATION of a session / an invitation:
| sessions that are open and matches that are on go on to their end (docs/16 §3.2).
*/

return [
    'access' => env('PRACTICE_ACCESS', 'off'),          // off | pilot | all
    'plan' => env('PRACTICE_PLAN', 'all'),              // all | pro
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
        'presence_grace_seconds' => 30, 'presence_warn_seconds' => 10, 'heartbeat_write_seconds' => 5, 'deadline_grace_ms' => 1500,
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
            'first', 'second', 'third', 'half'],
    ],
    'import' => ['max_bytes' => 2 * 1024 * 1024, 'max_items' => 300],
    // config/filesystems.php: root storage/app/practice-audio, no url - the files are served only by the audio endpoint (docs/16 §10.5).
    'audio' => ['disk' => 'practice_audio', 'dir' => ''],
];
