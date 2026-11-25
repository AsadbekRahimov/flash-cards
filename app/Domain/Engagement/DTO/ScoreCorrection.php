<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;

/**
 * What a correction of a rating day did ({@see ScoreCorrectionService}): the credits whose state it changed (empty = a repeat: nothing
 * changed, nothing was written), the counts of the day before and after, the revision of the day after, and whether the weekly count
 * was left as it was because the week of the day is final (its group closed, or the week ended).
 */
final readonly class ScoreCorrection
{
    /** @param  list<int>  $changedCreditIds */
    public function __construct(
        public string $ratingDay,
        public array $changedCreditIds,
        public int $weekPointsBefore,
        public int $weekPointsAfter,
        public int $monthPointsBefore,
        public int $monthPointsAfter,
        public int $revision,
        public bool $weekFrozen,
    ) {}

    public function changed(): bool
    {
        return $this->changedCreditIds !== [];
    }

    /** @return array{rating_day: string, credits: list<int>, week: array{0: int, 1: int}, month: array{0: int, 1: int}, revision: int, week_frozen: bool} */
    public function toArray(): array
    {
        return [
            'rating_day' => $this->ratingDay,
            'credits' => $this->changedCreditIds,
            'week' => [$this->weekPointsBefore, $this->weekPointsAfter],
            'month' => [$this->monthPointsBefore, $this->monthPointsAfter],
            'revision' => $this->revision,
            'week_frozen' => $this->weekFrozen,
        ];
    }
}
