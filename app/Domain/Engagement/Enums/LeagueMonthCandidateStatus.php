<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `league_month_candidates.status` (docs/13 §7.6): frozen at the close, settled by the administrator's review. */
enum LeagueMonthCandidateStatus: string
{
    case Candidate = 'candidate';
    case Winner = 'winner';
    case Disqualified = 'disqualified';
}
