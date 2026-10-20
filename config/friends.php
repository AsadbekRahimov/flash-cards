<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Friends and challenges (docs/13 §1.5, §7.5 — task 2C.5)
|--------------------------------------------------------------------------
*/

return [
    /** Accepted friends one learner may have (and, with pending requests they sent, may occupy). */
    'max' => 100,

    'challenges' => [
        /** Length of a challenge in local days of the challenger (it starts the day after it is accepted). */
        'days' => 7,

        /** A challenge nobody accepted within this many hours becomes `expired` (and stops blocking the pair). */
        'pending_ttl_hours' => 72,
    ],
];
