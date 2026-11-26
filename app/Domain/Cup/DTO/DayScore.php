<?php

declare(strict_types=1);

namespace App\Domain\Cup\DTO;

/** The points of one day of a participant (`cup_day_scores`): the round and the duel. */
final readonly class DayScore
{
    public function __construct(
        public int $day,
        public int $round,
        public int $duel = 0,
    ) {}

    public function total(): int
    {
        return $this->round + $this->duel;
    }
}
