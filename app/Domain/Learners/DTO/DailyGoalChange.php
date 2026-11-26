<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Services\DailyGoalLimiter;

/**
 * What {@see DailyGoalLimiter::enforce()} changed (docs/34 §2б.2): the goal was lowered to the limit
 * of the plan (`limited`) or the remembered goal came back (`restored`). `goalMet` - the lowering completed today's goal
 * (`DailyStatsService::reconcileGoal`), so the day rows read before the call are stale.
 */
final readonly class DailyGoalChange
{
    public const LIMITED = 'limited';

    public const RESTORED = 'restored';

    /**
     * @param  'limited'|'restored'  $kind
     */
    public function __construct(
        public string $kind,
        public int $from,
        public int $to,
        public bool $goalMet = false,
    ) {}

    public function isLimited(): bool
    {
        return $this->kind === self::LIMITED;
    }
}
