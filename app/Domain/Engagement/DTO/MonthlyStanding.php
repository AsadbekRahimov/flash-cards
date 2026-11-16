<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/**
 * One row of the public top list of a month (docs/13 §7.6): the rating points (capped per day), the active days and the
 * learner's first name — nothing else of other people leaves the server. Cached as arrays ({@see toArray()}): a cache
 * holds scalars only (docs/19 §3).
 *
 * @phpstan-type MonthlyStandingArray array{learner_id: int, name: string|null, points: int, active_days: int, rank: int}
 */
final readonly class MonthlyStanding
{
    public function __construct(
        public int $learnerId,
        public ?string $name,
        public int $points,
        public int $activeDays,
        public int $rank,
    ) {}

    /** @return MonthlyStandingArray */
    public function toArray(): array
    {
        return [
            'learner_id' => $this->learnerId,
            'name' => $this->name,
            'points' => $this->points,
            'active_days' => $this->activeDays,
            'rank' => $this->rank,
        ];
    }

    /** @param  MonthlyStandingArray  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['learner_id'], $data['name'], (int) $data['points'], (int) $data['active_days'], (int) $data['rank']);
    }
}
