<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

use Carbon\CarbonImmutable;

/**
 * One local calendar day of a learner expressed as a half-open UTC interval
 * `[startUtc, endUtc)` (LearnerClock::dayBoundsUtc, docs/12 §3.1).
 *
 * Because both ends are wall-clock midnights, the interval is 23 h on a
 * spring-forward day and 25 h on a fall-back day — exactly what "everything
 * the learner did on date X" means to the learner.
 *
 * `LearnerClock::limitWindowUtc` returns the same shape for the window of a
 * per-day limit; after a timezone change that window is the previous zone's
 * day *extended* to the new zone's next midnight, so `endUtc - startUtc` may
 * then exceed a calendar day (docs/12 §5.3).
 */
final readonly class DayBounds
{
    /**
     * @param  string  $date  the local date `Y-m-d` this interval covers
     * @param  string  $timezone  IANA zone the bounds were computed in
     */
    public function __construct(
        public string $date,
        public string $timezone,
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
    ) {}

    public function contains(CarbonImmutable $instant): bool
    {
        return ! $instant->isBefore($this->startUtc) && $instant->isBefore($this->endUtc);
    }

    /**
     * Serialisable form for `learners.meta` (ISO-8601 UTC instants).
     *
     * @return array{date: string, timezone: string, start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'timezone' => $this->timezone,
            'start' => $this->startUtc->utc()->toIso8601String(),
            'end' => $this->endUtc->utc()->toIso8601String(),
        ];
    }

    /** Inverse of {@see toArray()}; null for anything that is not a well-formed record (bad data never throws). */
    public static function tryFromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $date = $value['date'] ?? null;
        $timezone = $value['timezone'] ?? null;
        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;

        if (! is_string($date) || ! is_string($timezone) || ! is_string($start) || ! is_string($end)) {
            return null;
        }

        try {
            $startUtc = CarbonImmutable::parse($start)->utc();
            $endUtc = CarbonImmutable::parse($end)->utc();
        } catch (\Throwable) {
            return null;
        }

        if (! $endUtc->isAfter($startUtc)) {
            return null;
        }

        return new self($date, $timezone, $startUtc, $endUtc);
    }
}
