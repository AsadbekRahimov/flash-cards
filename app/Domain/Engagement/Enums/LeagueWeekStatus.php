<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `league_weeks.status` (docs/13 §1.5): `open` → `closing` (inside the closing transaction) → `closed`. */
enum LeagueWeekStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
}
