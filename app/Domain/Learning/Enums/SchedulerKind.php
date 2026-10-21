<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Which spaced-repetition algorithm schedules a learner's cards (docs/06 §9, docs/13 §8.3).
 * Stored in `learners.scheduler`, `word_repetitions.scheduler` and `training_reviews.scheduler`.
 */
enum SchedulerKind: string
{
    case Sm2 = 'sm2';
    case Fsrs = 'fsrs';

    public function label(): string
    {
        return match ($this) {
            self::Sm2 => 'SM-2',
            self::Fsrs => 'FSRS-5',
        };
    }
}
