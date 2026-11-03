<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The week of the boosters (docs/18 §12.7.2): Monday-based, in the learner's local zone - the same reading as the refill of the
 * streak freezes (`StreakFreezeService::refill`). `game_booster_uses.week_start` stores the Monday as a date; the limit resets at the
 * next local Monday's midnight.
 */
final class GameWeek
{
    /** The Monday of the local week `$localToday` (the learner's local midnight, `LearnerClock::today()`) belongs to. */
    public static function start(CarbonImmutable $localToday): CarbonImmutable
    {
        return $localToday->startOfWeek(CarbonInterface::MONDAY);
    }

    /** The first instant of the next local week: when the weekly limit resets. */
    public static function end(CarbonImmutable $localToday): CarbonImmutable
    {
        return self::start($localToday)->addWeek();
    }
}
