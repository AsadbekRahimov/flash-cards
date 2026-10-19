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
     */
    public function __construct(
        public int $current,
        public int $best,
        public bool $metToday,
        public bool $atRisk,
    ) {}

    /** @return array{current: int, best: int, met_today: bool, at_risk: bool} */
    public function toArray(): array
    {
        return [
            'current' => $this->current,
            'best' => $this->best,
            'met_today' => $this->metToday,
            'at_risk' => $this->atRisk,
        ];
    }
}
