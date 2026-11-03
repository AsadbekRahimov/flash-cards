<?php

declare(strict_types=1);

namespace App\Domain\Learners\Enums;

use App\Models\LearnerDailyStats;

/**
 * What held a local day for the streak (docs/12 §7.4, docs/06 §6a, docs/18 §12.6): the daily goal of cards, a finished run of the
 * game, or a streak freeze. The first two count `+1`; a freeze only bridges. It is also the value of `/today.streak.held_by`.
 */
enum StreakHeldBy: string
{
    case Goal = 'goal';
    case Game = 'game';
    case Freeze = 'freeze';

    /** The facts of a day row: the goal first (a day with both the goal and a run is a goal day), then the game, then a freeze. */
    public static function fromRow(LearnerDailyStats $row): ?self
    {
        return match (true) {
            $row->goal_met_at !== null => self::Goal,
            $row->game_held_at !== null => self::Game,
            $row->frozen_at !== null => self::Freeze,
            default => null,
        };
    }

    /** Whether a day held this way adds one to the series (a frozen day keeps it unbroken without counting). */
    public function counts(): bool
    {
        return $this !== self::Freeze;
    }
}
