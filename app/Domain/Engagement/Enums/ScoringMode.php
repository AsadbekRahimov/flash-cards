<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * `leagues.scoring.mode` (docs/32 §6.1): `off` — nothing of the word points is written; `shadow` — the credits are written (state,
 * day rows, journal) but every period stays on the version-1 rules and nothing is shown; `live` — the periods that start on or
 * after a version's `starts_on` count by it. By the owner's decision (docs/31 §5, В1) there is no shadow month before the launch;
 * `shadow` stays as the "record only" fallback.
 */
enum ScoringMode: string
{
    case Off = 'off';
    case Shadow = 'shadow';
    case Live = 'live';
}
