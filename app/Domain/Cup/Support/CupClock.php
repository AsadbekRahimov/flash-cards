<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The calendar of a cup (docs/33 §3.1, §5): every border is a MIDNIGHT of the cup's timezone, kept in UTC. Pure functions; the
 * timezone is the cup's (`cups.timezone`), never the learner's - all participants share the same days.
 */
final class CupClock
{
    /**
     * The start of day 1 for a cup published at `$now`: the nearest local midnight at least `$minLeadHours` away (otherwise the next
     * one), so that "Старт завтра в 00:00" never means "in ten minutes".
     */
    public static function firstStart(CarbonInterface $now, string $timezone, int $minLeadHours): CarbonImmutable
    {
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        $midnight = $local->startOfDay()->addDay();
        if ($midnight->diffInSeconds($local, true) < $minLeadHours * 3600) {
            $midnight = $midnight->addDay();
        }

        return $midnight->utc();
    }

    /** The UTC start of day `$day` (1-based) of a cup that starts at `$startsAt`. */
    public static function dayStart(CarbonInterface $startsAt, string $timezone, int $day): CarbonImmutable
    {
        return CarbonImmutable::instance($startsAt)->setTimezone($timezone)->startOfDay()->addDays($day - 1)->utc();
    }

    /** The UTC end of the last day: the local midnight `$days` days after the start. */
    public static function endOf(CarbonInterface $startsAt, string $timezone, int $days): CarbonImmutable
    {
        return self::dayStart($startsAt, $timezone, $days + 1);
    }

    /**
     * The 1-based number of the day `$now` falls into; 0 before the start; `$days + 1` (or more) after the end. The count is by
     * local calendar dates, so a day of 23 or 25 hours (a DST change elsewhere) is still one day.
     */
    public static function dayNumber(CarbonInterface $startsAt, string $timezone, CarbonInterface $now): int
    {
        $start = CarbonImmutable::instance($startsAt)->setTimezone($timezone)->startOfDay();
        $local = CarbonImmutable::instance($now)->setTimezone($timezone);
        if ($local->lt($start)) {
            return 0;
        }

        return (int) $start->diffInDays($local->startOfDay(), true) + 1;
    }

    /** The same start moved to the next local midnight (the start waits for a second participant). */
    public static function nextDay(CarbonInterface $startsAt, string $timezone): CarbonImmutable
    {
        return self::dayStart($startsAt, $timezone, 2);
    }
}
