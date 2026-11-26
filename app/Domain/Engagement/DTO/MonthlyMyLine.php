<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

use App\Domain\Engagement\Enums\ScoreCategory;

/**
 * The learner's own line of a month (docs/13 §7.6): place among the participants (`null` while not a participant — opted
 * out or blocked; the onboarding in the bot is not asked for since docs/34 §3), points, and the prize eligibility with what
 * is still missing for it. `notCountedReason` tells the viewer why they have no place: `opted_out`, `blocked`, or null
 * (a participant, or a row not recounted yet). A v2 month (docs/32 §5.2) adds the table of the line (`category`, null in a v1 month)
 * and the tie-break fact `longWords`; the rank is then the competition rank inside the category (1, 2, 2, 4). They are not in
 * {@see toArray()} yet: the API of the v2 month is S12 (`LeagueBoardResource`).
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
        public ?ScoreCategory $category = null,
        public int $longWords = 0,
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
