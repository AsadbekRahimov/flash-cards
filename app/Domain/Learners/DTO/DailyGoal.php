<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * The learner's daily goal for the current local day (docs/12 §7.3, §7.4).
 */
final readonly class DailyGoal
{
    /**
     * @param  int  $target  `learners.daily_goal`, in graded cards
     * @param  int  $done  `learner_daily_stats.reviews_count` of the local day
     * @param  bool  $met  `goal_met_at` is set — stays true when more cards are graded afterwards
     */
    public function __construct(
        public int $target,
        public int $done,
        public bool $met,
    ) {}

    /** @return array{target: int, done: int, met: bool} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'done' => $this->done,
            'met' => $this->met,
        ];
    }
}
