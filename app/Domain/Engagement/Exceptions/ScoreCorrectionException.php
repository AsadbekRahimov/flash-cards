<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use RuntimeException;

/**
 * A correction of a rating day refused by {@see ScoreCorrectionService}; `errorCode` is what the panel shows (S15).
 */
final class ScoreCorrectionException extends RuntimeException
{
    public const DAY_NOT_FOUND = 'score_day_not_found';

    public const CREDIT_NOT_FOUND = 'score_credit_not_found';

    public const REASON_INVALID = 'score_reason_invalid';

    public const BUSY = 'score_day_busy';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function dayNotFound(int $learnerId, string $day): self
    {
        return new self(self::DAY_NOT_FOUND, "Learner {$learnerId} has no rating day {$day}.");
    }

    /** @param  list<int>  $ids */
    public static function creditNotFound(array $ids): self
    {
        return new self(self::CREDIT_NOT_FOUND, 'No such credit of this learner and day: '.implode(', ', $ids).'.');
    }

    public static function reasonInvalid(int $maxLength): self
    {
        return new self(self::REASON_INVALID, "A correction needs a reason of up to {$maxLength} characters.");
    }

    public static function busy(): self
    {
        return new self(self::BUSY, 'The day kept receiving new credits during the correction; try again.');
    }
}
