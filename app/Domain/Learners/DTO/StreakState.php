<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * A learner's streak as the client should see it (StreakService::current, docs/12 §7.3–7.4).
 */
final readonly class StreakState
{
    /**
     * @param  int  $current  consecutive local days with the daily goal met, ending today or yesterday; 0 once a day was skipped
     * @param  int  $best  longest series ever
     * @param  bool  $metToday  today's goal is already met (the series cannot break today)
     * @param  bool  $atRisk  `current > 0 && !metToday` — the series ends unless the goal is met before local midnight
     * @param  int  $freezes  streak freezes the learner can spend (0 without the Pro feature — docs/13 §7.2)
     * @param  bool  $frozenYesterday  yesterday was missed and held by a freeze (`learner_daily_stats.frozen_at`)
     */
    public function __construct(
        public int $current,
        public int $best,
        public bool $metToday,
        public bool $atRisk,
        public int $freezes = 0,
        public bool $frozenYesterday = false,
    ) {}

    /** @return array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool} */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'best' => $this->best,
            'met_today' => $this->metToday,
            'at_risk' => $this->atRisk,
            'freezes' => $this->freezes,
            'frozen_yesterday' => $this->frozenYesterday,
        ];
    }
}
