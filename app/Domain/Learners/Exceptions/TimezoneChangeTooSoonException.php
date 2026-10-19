<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\LearnerSettingsService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * `learners.timezone` may change at most once per {@see LearnerSettingsService::TIMEZONE_CHANGE_INTERVAL_HOURS}
 * (docs/12 §5.3: the zone drives every per-day window — quota, goal, streak, reminders — and
 * hopping between zones must not be a way to replay a day). The first zone, set in the
 * onboarding, is not a change. HTTP answers 422 `timezone_change_too_soon`; the bot explains in
 * the learner's language.
 */
final class TimezoneChangeTooSoonException extends RuntimeException
{
    public const ERROR_CODE = 'timezone_change_too_soon';

    public readonly string $errorCode;

    public function __construct(public readonly CarbonImmutable $availableAt)
    {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct('The time zone can be changed once in 24 hours; next change at '.$availableAt->utc()->toIso8601String().'.');
    }
}
