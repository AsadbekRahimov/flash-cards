<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** What a challenge counts: a column of `learner_daily_stats` summed over the period. */
enum ChallengeMetric: string
{
    case Reviews = 'reviews';
    case NewWords = 'new_words';

    public function column(): string
    {
        return match ($this) {
            self::Reviews => 'reviews_count',
            self::NewWords => 'new_words_count',
        };
    }
}
