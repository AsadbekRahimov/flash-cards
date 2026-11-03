<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use App\Domain\Learners\Enums\StreakHeldBy;

/**
 * A learner's streak as the client should see it (StreakService::current, docs/12 §7.3–7.4, docs/18 §12.6).
 */
final readonly class StreakState
{
    /**
     * @param  int  $current  consecutive local days held by the daily goal or a finished run of the game, ending today or yesterday; 0 once a day was skipped
     * @param  int  $best  longest series ever
     * @param  bool  $metToday  today is already held and counted (the series cannot break today)
     * @param  bool  $atRisk  `current > 0 && !metToday` — the series ends unless the goal is met or a run finished before local midnight
     * @param  int  $freezes  streak freezes the learner can spend (0 without the Pro feature — docs/13 §7.2)
     * @param  bool  $frozenYesterday  yesterday was missed and held by a freeze (`learner_daily_stats.frozen_at`)
     * @param  StreakHeldBy|null  $heldBy  what held today (`goal` wins over `game` when both happened); null while not `metToday`,
     *                                     or when the caller gave StreakService::current() no row of today
     */
    public function __construct(
        public int $current,
        public int $best,
        public bool $metToday,
        public bool $atRisk,
        public int $freezes = 0,
        public bool $frozenYesterday = false,
        public ?StreakHeldBy $heldBy = null,
    ) {}

    /** @return array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool, held_by: string|null} */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'best' => $this->best,
            'met_today' => $this->metToday,
            'at_risk' => $this->atRisk,
            'freezes' => $this->freezes,
            'frozen_yesterday' => $this->frozenYesterday,
            'held_by' => $this->heldBy?->value,
        ];
    }
}
