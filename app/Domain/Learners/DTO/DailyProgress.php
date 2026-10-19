<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * Goal and streak of the learner's current local day — what both `GET /today` and the
 * `/finish` summary show (TodayService::progress).
 */
final readonly class DailyProgress
{
    public function __construct(
        public DailyGoal $goal,
        public StreakState $streak,
    ) {}
}
