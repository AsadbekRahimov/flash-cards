<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `score_credits.source` (docs/32 §2.5): where the credited answer was given; `source_id` is the session or the run. */
enum ScoreSource: string
{
    case Training = 'training';
    case GroupLesson = 'group_lesson';
    case Game = 'game';
}
