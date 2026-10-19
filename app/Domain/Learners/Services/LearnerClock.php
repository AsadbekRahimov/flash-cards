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
 *    repeated hour already yields the next day).
 */
final class LearnerClock
{
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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            throw new InvalidArgumentException("Local date must be Y-m-d, got '{$localDate}'.");
        }

        $zone = $this->timezone($learner);
        $start = CarbonImmutable::parse($localDate.' 00:00:00', $zone);
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
