<?php

declare(strict_types=1);

namespace App\Domain\Organizations\DTO;

use App\Domain\Organizations\Exceptions\ReportRangeException;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A report period: two LOCAL dates (`Y-m-d`), both inclusive. `learner_daily_stats.date` is the
 * learner's local day; the organization's timezone is the approximation used for the whole center
 * (docs/13 §2.3). At most {@see MAX_DAYS} days.
 */
final readonly class DateRange
{
    public const MAX_DAYS = 366;

    public function __construct(public string $from, public string $to) {}

    /** @throws ReportRangeException */
    public static function between(string $from, string $to): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start === null || $end === null || $start->gt($end)) {
            throw new ReportRangeException(ReportRangeException::INVALID_RANGE, "Invalid report period {$from}..{$to}.");
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new ReportRangeException(ReportRangeException::RANGE_TOO_LONG, 'The report period is longer than '.self::MAX_DAYS.' days.');
        }

        return new self($start->toDateString(), $end->toDateString());
    }

    /** The last `$days` days ending today in the organization's timezone (today included). */
    public static function lastDays(int $days, Organization $organization, ?CarbonImmutable $now = null): self
    {
        $days = max(1, min($days, self::MAX_DAYS));
        $today = ($now ?? CarbonImmutable::now())->setTimezone($organization->timezone)->startOfDay();

        return new self($today->subDays($days - 1)->toDateString(), $today->toDateString());
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /** Start of the first day in the organization's timezone, as an instant. */
    public function startsAt(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from, $organization->timezone)->startOfDay();
    }

    /** End of the last day in the organization's timezone, as an instant. */
    public function endsAt(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to, $organization->timezone)->endOfDay();
    }

    private static function parse(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            return null;
        }

        return $parsed !== false && $parsed->toDateString() === $date ? $parsed : null;
    }
}
