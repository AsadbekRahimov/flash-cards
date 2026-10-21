<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\LeagueMember;
use App\Models\LeagueWeek;

/** Everything `GET /api/twa/league` shows (docs/04 A.13), assembled by LeagueService::overview. */
final readonly class LeagueOverview
{
    /**
     * @param  LeagueWeek|null  $week  the current week row; null while nobody was placed yet this week (or leagues are off)
     * @param  string  $weekStartsOn  `Y-m-d` Monday of the current week (league time zone)
     * @param  string  $weekEndsOn  `Y-m-d` Sunday
     * @param  string  $closesAt  ISO 8601 UTC instant of the next Monday 00:00 in the league zone
     * @param  list<string>  $tiers  tier keys, index = tier
     * @param  LeagueMember|null  $lastResult  the learner's most recent closed membership (result known)
     */
    public function __construct(
        public bool $enabled,
        public ?LeagueWeek $week,
        public string $weekStartsOn,
        public string $weekEndsOn,
        public string $closesAt,
        public int $capacity,
        public int $promote,
        public int $demote,
        public array $tiers,
        public bool $optedOut,
        public ?LeagueMembershipView $membership,
        public ?LeagueMember $lastResult,
    ) {}
}
