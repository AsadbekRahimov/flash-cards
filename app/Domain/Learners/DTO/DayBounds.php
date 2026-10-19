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
}
