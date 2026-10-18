<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * How much of a course / lesson / stage the learner has learned: `done` of
 * `total` ACTIVE words (`words.is_active`), "learned" as in
 * `LearnerStatsService` (`word_repetitions.repetitions >= 1`).
 */
final readonly class LearnerProgress
{
    public function __construct(
        public int $done,
        public int $total,
    ) {}
}
