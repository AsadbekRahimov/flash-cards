<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * The learner's own line of a month (docs/13 §7.6): place among the participants (`null` while not a participant — opted
 * out, blocked, onboarding unfinished), points, and the prize eligibility with what is still missing for it.
 */
final readonly class MonthlyMyLine
{
    public function __construct(
        public ?int $rank,
        public int $points,
        public int $activeDays,
        public bool $participant,
        public bool $eligible,
        public int $missingActiveDays,
        public int $missingAccountDays,
    ) {}

    /**
     * @return array{rank: int|null, points: int, active_days: int, participant: bool, eligible: bool, missing: array{active_days: int, account_days: int}}
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'points' => $this->points,
            'active_days' => $this->activeDays,
            'participant' => $this->participant,
            'eligible' => $this->eligible,
            'missing' => ['active_days' => $this->missingActiveDays, 'account_days' => $this->missingAccountDays],
        ];
    }
}
