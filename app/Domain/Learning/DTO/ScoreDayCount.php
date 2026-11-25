<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * One count (weekly or monthly) of the learner's rating day after an answer was credited (docs/32 §2.4, §8.1 `score.today`): the points
 * and the slots used, under the category and the rules version of its period. The limits are null for a count that is still on the v1
 * rules (version 1: nothing is counted there).
 */
final readonly class ScoreDayCount
{
    public function __construct(
        public ScoreCategory $category,
        public int $version,
        public int $points,
        public ?int $cap,
        public int $newUsed,
        public ?int $newLimit,
        public int $reviewUsed,
        public ?int $reviewLimit,
    ) {}

    /** @return array{points: int, cap: int|null, category: string, new: array{0: int, 1: int|null}, review: array{0: int, 1: int|null}} */
    public function toArray(): array
    {
        return [
            'points' => $this->points,
            'cap' => $this->cap,
            'category' => $this->category->value,
            'new' => [$this->newUsed, $this->newLimit],
            'review' => [$this->reviewUsed, $this->reviewLimit],
        ];
    }
}
