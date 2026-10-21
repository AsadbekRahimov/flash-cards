<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `league_members.result`, written once when the week is closed (docs/13 §1.5). */
enum LeagueResult: string
{
    case Promoted = 'promoted';
    case Stayed = 'stayed';
    case Demoted = 'demoted';

    /** Change of tier the result implies for the next placement. */
    public function tierDelta(): int
    {
        return match ($this) {
            self::Promoted => 1,
            self::Stayed => 0,
            self::Demoted => -1,
        };
    }
}
