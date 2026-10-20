<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * One row of a group's standings (docs/13 §2.4): points summed from `learner_daily_stats` for the
 * week (or the final columns once the week is closed), ranked by points, ties by the earlier
 * placement (`league_members.id`). `name` is the learner's first name — nothing else of other
 * people leaves the server.
 */
final readonly class LeagueStanding
{
    public function __construct(
        public int $memberId,
        public int $learnerId,
        public ?string $name,
        public int $points,
        public int $rank,
    ) {}
}
