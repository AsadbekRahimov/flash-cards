<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * The learner's own group this week (docs/04 A.13): place, points, what it takes to move up or
 * to leave the demotion zone, and the standings of the group.
 *
 * `zone`: `promotion` (top-N), `demotion` (bottom-N, only when the tier can demote), else `safe`.
 * `pointsToPromote`: points missing to enter the top-N (null when already there or the tier cannot promote).
 * `pointsToSafety`: points missing to leave the bottom-N (null when not in it or the tier cannot demote).
 *
 * @phpstan-type Zone 'promotion'|'safe'|'demotion'
 */
final readonly class LeagueMembershipView
{
    /**
     * @param  Zone  $zone
     * @param  list<LeagueStanding>  $standings
     */
    public function __construct(
        public int $tier,
        public string $tierKey,
        public int $rank,
        public int $points,
        public int $membersCount,
        public string $zone,
        public ?int $pointsToPromote,
        public ?int $pointsToSafety,
        public array $standings,
    ) {}
}
