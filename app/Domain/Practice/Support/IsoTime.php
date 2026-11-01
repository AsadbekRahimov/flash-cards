<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** The format of every date of the practice API (docs/16 §7): ISO 8601, UTC, with milliseconds - `2026-10-30T10:00:05.321Z`. */
final class IsoTime
{
    public static function format(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
