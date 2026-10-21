<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Engagement\DTO\TodayLeague;

/**
 * Everything the "Today" screen needs (docs/12 §7.3), assembled by TodayService.
 */
final readonly class TodaySummary
{
    /**
     * @param  int  $dueCount  cards due for review (DueRepetitionCriteria — active words only)
     * @param  int|null  $newWordsRemaining  Free quota left for the day (NewWordQuota); null = unlimited
     * @param  int|null  $openSessionId  the open, unexpired `daily_review` session to continue
     * @param  TodayLeague|null  $league  the learner's place in this week's league group; null while not placed
     */
    public function __construct(
        public int $dueCount,
        public ?int $newWordsRemaining,
        public DailyProgress $progress,
        public ?TodayCourse $activeCourse,
        public ?int $openSessionId,
        public Entitlement $entitlement,
        public bool $onboardingCompleted,
        public ?TodayLeague $league = null,
    ) {}
}
