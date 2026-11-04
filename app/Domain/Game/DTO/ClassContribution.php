<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * What a finished run gave the caravan of its class (docs/18 §12.5.3 step 6, G2.9a): the totals after it, and whether this very run
 * opened the boss of the class or made the caravan beat it.
 */
final readonly class ClassContribution
{
    public function __construct(
        public int $caravanId,
        public int $stars,
        public int $starsTotal,
        public int $targetStars,
        public int $runsTotal,
        /** `boss_unlocked_at` was set by this run. */
        public bool $bossUnlocked,
        /** `boss_defeated_at` was set by this run (a `class_boss` victory that made the wins reach half of the participants). */
        public bool $bossDefeated,
    ) {}
}
