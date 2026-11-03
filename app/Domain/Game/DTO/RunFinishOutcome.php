<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

use App\Domain\Game\Enums\LedgerKind;

/**
 * What the transaction of a finish decided (docs/18 §12.5.3): the stars, the coins and the league points of the run, and the facts the
 * hooks after the commit need - whether this run held the day (G2.10: the streak), whether it was the first activity of the day (G2.10:
 * the placement in the league), whether it cleared the stop or beat the boss for the first time (G2.4: the achievement and the card).
 */
final readonly class RunFinishOutcome
{
    public function __construct(
        public int $stars,
        public int $coins,
        public LedgerKind $coinsKind,
        public int $balance,
        public int $leaguePoints,
        /** `game_held_at` was set by this run (it was NULL before). */
        public bool $heldDay,
        /** `goal_met_at` of the day row was set already when the run finished. */
        public bool $goalMetBefore,
        /** `game_runs_count` of the day became 1 with this run. */
        public bool $firstRunOfDay,
        /** `reviews_count` of the day row when the run finished. */
        public int $reviewsCount,
        /** The stop got its `first_cleared_at` with this run. */
        public bool $firstClear,
        public bool $bossWon,
        /** The city got its `boss_defeated_at` with this run. */
        public bool $bossFirst,
    ) {}
}
