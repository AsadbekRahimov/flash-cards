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
     * @param  string  $timezone  IANA id of the zone the learner's day (goal, streak) is counted in: the one LearnerClock uses
     * @param  TodayLeague|null  $league  the learner's place in this week's league group; null while not placed
     * @param  array{daily_available: bool, active_run: array{id: int, kind: string, progress: array{answered: int, total: int}}|null, class_caravan: array{id: int, stars_total: int, target_stars: int, ends_at: string}|null}  $game  the entry "Caravan" (docs/04 A.2a)
     */
    public function __construct(
        public int $dueCount,
        public ?int $newWordsRemaining,
        public DailyProgress $progress,
        public ?TodayCourse $activeCourse,
        public ?int $openSessionId,
        public Entitlement $entitlement,
        public bool $onboardingCompleted,
        public string $timezone,
        public ?TodayLeague $league = null,
        public array $game = ['daily_available' => false, 'active_run' => null, 'class_caravan' => null],
    ) {}
}
