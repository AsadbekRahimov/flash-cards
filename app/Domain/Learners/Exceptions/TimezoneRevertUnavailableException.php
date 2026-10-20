<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\LearnerSettingsService;
use RuntimeException;

/**
 * {@see LearnerSettingsService::revertTimezone()} has nothing to take back: no recent change of the
 * zone, the {@see LearnerSettingsService::TIMEZONE_UNDO_MINUTES} minutes have passed, or the undo was
 * already used. HTTP answers 422 `timezone_revert_unavailable`.
 */
final class TimezoneRevertUnavailableException extends RuntimeException
{
    public const ERROR_CODE = 'timezone_revert_unavailable';

    public readonly string $errorCode;

    public function __construct()
    {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct('The time zone change can be taken back only within '.LearnerSettingsService::TIMEZONE_UNDO_MINUTES.' minutes and only once.');
    }
}
