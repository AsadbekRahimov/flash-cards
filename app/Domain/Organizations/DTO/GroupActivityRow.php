<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

/** One group of the activity report (docs/13 §2.3): the sum over its active learners. */
final readonly class GroupActivityRow
{
    public function __construct(
        public int $groupId,
        public string $title,
        public int $learners,
        public int $activeLearners,
        public int $reviews,
        public int $newWords,
        public int $goalMetDays,
        public int $assignmentsDone,
        public int $assignmentsTotal,
    ) {}
}
