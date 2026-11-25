<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `learner_score_periods.period` (docs/32 §2.3): the two independent counts of the word points. */
enum ScorePeriod: string
{
    case Week = 'week';
    case Month = 'month';
}
