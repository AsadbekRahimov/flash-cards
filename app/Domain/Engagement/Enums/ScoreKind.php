<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * `score_credits.kind` (docs/28, docs/32 §3.3): the first confirmation of a new word, a repetition on time, a repetition after an
 * interval of at least `long_interval_days`. The points of each are the rules version's (`leagues.scoring.versions.*.points`).
 */
enum ScoreKind: string
{
    case New = 'new';
    case Review = 'review';
    case Long = 'long';
}
