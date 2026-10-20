<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use Carbon\CarbonImmutable;

/**
 * One learner of the activity report (docs/13 §2.3, §6.4): aggregates of `learner_daily_stats` and
 * the assignments' results only — never a session or an answer (docs/12 §4.4). `name` is the
 * display name (first/last name, else `@username`, else `#id`).
 */
final readonly class LearnerActivityRow
{
    public function __construct(
        public int $learnerId,
        public string $name,
        public ?string $username,
        public int $reviews,
        public int $newWords,
        public int $daysActive,
        public int $goalMetDays,
        public int $streakCurrent,
        public int $assignmentsDone,
        public int $assignmentsTotal,
        public ?CarbonImmutable $lastSeenAt,
    ) {}
}
