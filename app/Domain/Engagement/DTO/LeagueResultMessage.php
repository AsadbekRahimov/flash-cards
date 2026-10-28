<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\LeagueResult;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use Carbon\CarbonInterface;

/**
 * A week-result DM that is worth sending (LeagueResultSender::prepare): the member of a closed week, its group
 * and verdict, and the learner who is neither banned nor DM-closed nor opted out. `closedAt` is when the week was
 * closed — the DM is sent at most once since then.
 */
final readonly class LeagueResultMessage
{
    public function __construct(
        public LeagueMember $member,
        public Learner $learner,
        public LeagueWeek $week,
        public LeagueGroup $group,
        public LeagueResult $result,
        public CarbonInterface $closedAt,
    ) {}
}
