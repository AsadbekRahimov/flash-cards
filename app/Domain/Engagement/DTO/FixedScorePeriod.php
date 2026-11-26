<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Domain\Engagement\Services\Scoring\ScorePeriodService;

/**
 * The fixed category and rules version of a learner's week or month (`learner_score_periods`, docs/32 §2.3), as returned by
 * {@see ScorePeriodService}. `created` = this call fixed it (the first credit of the period:
 * the moment the weekly placement of v2 hangs on, docs/32 §5.1); false = it was fixed before, by this or a parallel request, and the
 * category asked for was ignored.
 */
final readonly class FixedScorePeriod
{
    public function __construct(
        public ScorePeriod $period,
        public string $startsOn,
        public ScoreCategory $category,
        public int $rulesVersion,
        public bool $created,
    ) {}
}
