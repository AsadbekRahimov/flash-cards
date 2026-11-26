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
 * A v2 week (docs/32 §5.1, S8) adds the table of the group (`category`, null in a v1 week), the `rulesVersion` of the week and
 * `nextGap` - the points that overtake the nearest member with a strictly better tuple for sure (docs/32 §5.3; null for a leader);
 * the rank is then the competition rank (1, 2, 2, 4) and the standings carry the tie-break facts.
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
        public ?string $category = null,
        public int $rulesVersion = 1,
        public ?int $nextGap = null,
    ) {}
}
