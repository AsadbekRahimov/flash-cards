<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * The learner's own line of a month (docs/13 §7.6): place among the participants (`null` while not a participant — opted
 * out or blocked; the onboarding in the bot is not asked for since docs/34 §3), points, and the prize eligibility with what
 * is still missing for it. `notCountedReason` tells the viewer why they have no place: `opted_out`, `blocked`, or null
 * (a participant, or a row not recounted yet).
 */
final readonly class MonthlyMyLine
{
    public const string REASON_OPTED_OUT = 'opted_out';

    public const string REASON_BLOCKED = 'blocked';

    /**
     * @param  self::REASON_*|null  $notCountedReason
     */
    public function __construct(
        public ?int $rank,
        public int $points,
        public int $activeDays,
        public bool $participant,
        public bool $eligible,
        public int $missingActiveDays,
        public int $missingAccountDays,
        public ?string $notCountedReason = null,
    ) {}

    /**
     * @return array{rank: int|null, points: int, active_days: int, participant: bool, eligible: bool, missing: array{active_days: int, account_days: int}, not_counted_reason: 'opted_out'|'blocked'|null}
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
            'not_counted_reason' => $this->notCountedReason,
        ];
    }
}
