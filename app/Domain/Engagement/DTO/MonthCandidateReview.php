<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\LeagueMonthCandidate;
use App\Models\Learner;

/**
 * A candidate as the administrator's review page shows it (docs/13 §7.6): the frozen row, the learner (the panel may
 * show id and name; it is an administrator) and the place the candidate would get if the month were confirmed now
 * (`null`: disqualified or beyond the prize places).
 */
final readonly class MonthCandidateReview
{
    public function __construct(
        public LeagueMonthCandidate $candidate,
        public ?Learner $learner,
        public ?int $provisionalPlace,
    ) {}
}
