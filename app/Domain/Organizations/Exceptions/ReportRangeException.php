<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use App\Domain\Organizations\DTO\DateRange;
use RuntimeException;

/**
 * Why a report period is rejected (docs/13 §2.3 OrgActivityReport):
 *
 *   invalid_range   a date is not `Y-m-d`, or `from` is after `to`
 *   range_too_long  the period is longer than {@see DateRange::MAX_DAYS} days
 */
final class ReportRangeException extends RuntimeException
{
    public const INVALID_RANGE = 'invalid_range';

    public const RANGE_TOO_LONG = 'range_too_long';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
