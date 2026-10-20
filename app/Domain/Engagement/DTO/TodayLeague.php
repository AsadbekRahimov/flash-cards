<?php

declare(strict_types=1);

namespace App\Domain\Engagement\DTO;

/** The one-line league summary of the "Today" screen (docs/13 §7.3). */
final readonly class TodayLeague
{
    public function __construct(
        public int $tier,
        public string $tierKey,
        public int $rank,
        public int $points,
        public int $membersCount,
    ) {}

    /** @return array{tier: int, tier_key: string, rank: int, points: int, members_count: int} */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier,
            'tier_key' => $this->tierKey,
            'rank' => $this->rank,
            'points' => $this->points,
            'members_count' => $this->membersCount,
        ];
    }
}
