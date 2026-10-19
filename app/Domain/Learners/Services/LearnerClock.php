<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\DayBounds;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The learner's local calendar (docs/12 §3.1, §5.3, §7.4, §7.5).
 *
 * Everything "per day" in the product — daily stats, goal, streak, Free quota,
 * reminders — is defined on the learner's **local** date in `learners.timezone`
 * (IANA). This is the only place that converts between UTC instants and that
 * local calendar, so DST and timezone edge cases live here and nowhere else.
 *
 * Rules:
 *  - an unknown / empty `timezone` falls back to `config('learners.default_timezone')`,
 *    then to UTC — never throws because of bad learner data (1.6 validates input);
 *  - day boundaries are wall-clock midnights, so a local day is 23 h or 25 h on a
 *    DST transition day (see DayBounds);
 *  - a non-existent wall-clock time (spring-forward gap) resolves forward to the
 *    first existing instant, as PHP does; an ambiguous one (fall-back) resolves in
 *    the offset of the instant the computation starts from — either pass may be
 *    picked, but never both on the same night (`nextOccurrenceUtc` from inside the
 *    repeated hour already yields the next day);
 *  - per-day limits read `limitWindowUtc()`, not `dayBoundsUtc()`: a timezone change
 *    carries the running day over so that no zone-hopping can shorten it (docs/12 §5.3).
 */
final class LearnerClock
{
    /** `learners.meta` key of the day carried across a timezone change (see {@see limitWindowUtc()}). */
    public const DAY_CARRY_META = 'local_day_carry';

    public function timezone(Learner $learner): DateTimeZone
    {
        return $this->makeZone($learner->timezone)
            ?? $this->makeZone((string) config('learners.default_timezone'))
            ?? new DateTimeZone('UTC');
    }

    /** Local midnight that starts the learner's current day (in the learner's zone). */
    public function today(Learner $learner, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return $now->setTimezone($this->timezone($learner))->startOfDay();
    }

    /** `Y-m-d` of the learner's current local day — the key of `learner_daily_stats`. */
    public function localDate(Learner $learner, ?CarbonImmutable $now = null): string
    {
        return $this->today($learner, $now)->toDateString();
    }

    /** UTC half-open interval of the local day that contains `$now` (default: the current day). */
    public function dayBoundsUtc(Learner $learner, ?CarbonImmutable $now = null): DayBounds
    {
        return $this->localDateBoundsUtc($learner, $this->localDate($learner, $now));
    }

    /**
     * UTC half-open interval of an arbitrary local date `Y-m-d` of the learner.
     *
     * The end is the midnight that starts the next calendar date (wall-clock
     * arithmetic), not `start + 24 h`.
     */
    public function localDateBoundsUtc(Learner $learner, string $localDate): DayBounds
    {
        $zone = $this->timezone($learner);
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $zone)
            : null;

        // createFromFormat rolls '2026-02-30' over to March: a date that moved is not a date.
        if (! $start instanceof CarbonImmutable || $start->format('Y-m-d') !== $localDate) {
            throw new InvalidArgumentException("Local date must be a valid Y-m-d, got '{$localDate}'.");
        }

        $end = $start->addDay()->startOfDay();

        return new DayBounds(
            $start->toDateString(),
            $zone->getName(),
            $start->utc(),
            $end->utc(),
        );
    }

    /**
     * First instant strictly after `$after` at which the learner's wall clock
     * shows `$localTime` (`HH:MM` or `HH:MM:SS`), in UTC. Used for
     * `learners.next_reminder_at` (docs/12 §7.5): recomputed after every send,
     * so DST shifts and timezone changes are picked up automatically.
     */
    public function nextOccurrenceUtc(Learner $learner, string $localTime, CarbonImmutable $after): CarbonImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $localTime) !== 1) {
            throw new InvalidArgumentException("Local time must be HH:MM or HH:MM:SS, got '{$localTime}'.");
        }

        $local = $after->setTimezone($this->timezone($learner));
        $candidate = $local->setTimeFromTimeString($localTime);

        if (! $candidate->isAfter($after)) {
            $candidate = $local->addDay()->setTimeFromTimeString($localTime);
        }

        return $candidate->utc();
    }

    /**
     * UTC window whose events count against a per-day limit right now (the Free new-word
     * quota, docs/12 §5.3). Normally the current local day. Right after a timezone change
     * it is the previous zone's day *carried over* (`learners.meta.local_day_carry`, written
     * by {@see carryDayAcrossTimezoneChange()}): the window keeps that day's start and lasts
     * until the first midnight of the new zone at or after that day's end. Consecutive
     * windows are therefore never shorter than a local day, whatever zones are chosen, so a
     * change of zone can neither shorten the day nor hand out a second one; the price is one
     * long day (under 48 h) after a change. `date` / `timezone` describe the carried day.
     */
    public function limitWindowUtc(Learner $learner, ?CarbonImmutable $now = null): DayBounds
    {
        $now ??= CarbonImmutable::now();

        $carry = $this->dayCarry($learner);
        if ($carry !== null) {
            $until = $this->firstMidnightAtOrAfter($learner, $carry->endUtc);
            if ($now->isBefore($until)) {
                return new DayBounds($carry->date, $carry->timezone, $carry->startUtc, $until);
            }
        }

        return $this->dayBoundsUtc($learner, $now);
    }

    /**
     * Marks the learner's current limit window as carried into the zone about to be set. Call
     * BEFORE `learners.timezone` is changed (the model must still carry the old zone); the
     * caller saves the model. A carry that is still active is kept: a second change during it
     * must not move the window's start forward. Not for the first zone of the onboarding —
     * nothing was counted in a day the learner never had.
     */
    public function carryDayAcrossTimezoneChange(Learner $learner, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $carry = $this->dayCarry($learner);
        if ($carry !== null && $now->isBefore($this->firstMidnightAtOrAfter($learner, $carry->endUtc))) {
            return;
        }

        $learner->meta = [...($learner->meta ?? []), self::DAY_CARRY_META => $this->dayBoundsUtc($learner, $now)->toArray()];
    }

    /** The carried day from `learners.meta`, if the record is well-formed. */
    public function dayCarry(Learner $learner): ?DayBounds
    {
        return DayBounds::tryFromArray(($learner->meta ?? [])[self::DAY_CARRY_META] ?? null);
    }

    /** Whether a carried day (see {@see limitWindowUtc()}) is still running, i.e. the quota window outlasts the local day. */
    public function hasActiveDayCarry(Learner $learner, ?CarbonImmutable $now = null): bool
    {
        $carry = $this->dayCarry($learner);

        return $carry !== null && ($now ?? CarbonImmutable::now())->isBefore($this->firstMidnightAtOrAfter($learner, $carry->endUtc));
    }

    /** First local midnight of the learner's zone at or after `$instant`, in UTC (wall-clock, so DST-correct). */
    public function firstMidnightAtOrAfter(Learner $learner, CarbonImmutable $instant): CarbonImmutable
    {
        $day = $this->dayBoundsUtc($learner, $instant);

        return $day->startUtc->equalTo($instant) ? $day->startUtc : $day->endUtc;
    }

    private function makeZone(?string $name): ?DateTimeZone
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
