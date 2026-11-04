<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use App\Domain\Game\Services\Runs\BossService;

/**
 * Whether the boss of a city may be fought (docs/18 §12.3.4): the learner has been introduced to at least `boss.min_words` words of
 * the city and has cleared (>= 1 star) at least `boss.min_cleared_stops` of its stops - or all of them when the city has fewer. The one
 * rule of the map (`boss.state`) and of the start of a fight ({@see BossService}).
 */
final readonly class BossReadiness
{
    public function __construct(
        public int $introduced,
        public int $required,
        public int $clearedStops,
        public int $requiredStops,
    ) {}

    /**
     * @param  int  $introduced  the learner's word_repetitions rows of the active words of the city
     * @param  int  $stops  the stops of the city (lessons with active words)
     * @param  int  $clearedStops  stops with >= 1 star
     */
    public static function of(int $introduced, int $stops, int $clearedStops): self
    {
        return new self(
            introduced: $introduced,
            required: (int) config('game.boss.min_words'),
            clearedStops: $clearedStops,
            requiredStops: min(max(1, (int) config('game.boss.min_cleared_stops')), $stops),
        );
    }

    public function isReady(): bool
    {
        return $this->introduced >= $this->required && $this->clearedStops >= $this->requiredStops;
    }
}
