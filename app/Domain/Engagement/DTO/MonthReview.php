<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Models\LeagueMonth;

/** What the administrator reviews of one month (docs/13 §7.6): the month, its prizes and the candidates by rank. */
final readonly class MonthReview
{
    /**
     * @param  list<MonthlyPrize>  $prizes
     * @param  list<MonthCandidateReview>  $candidates  by `rank`
     */
    public function __construct(
        public LeagueMonth $month,
        public array $prizes,
        public array $candidates,
    ) {}
}
