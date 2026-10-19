<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use RuntimeException;

/**
 * Why an onboarding / settings choice was not applied. Both cases are "quiet": a stale button
 * (an old message tapped again, or a double tap) and a tampered `callback_data` are answered
 * with an empty `answerCallbackQuery` and nothing else.
 *
 *   onboarding_stale_step    the tapped step is not the learner's current one
 *   onboarding_invalid_value the value is not in the step's whitelist
 */
final class OnboardingException extends RuntimeException
{
    public const STALE_STEP = 'onboarding_stale_step';

    public const INVALID_VALUE = 'onboarding_invalid_value';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function staleStep(string $step): self
    {
        return new self(self::STALE_STEP, "Onboarding step [{$step}] is not the current one.");
    }

    public static function invalidValue(string $step): self
    {
        return new self(self::INVALID_VALUE, "Invalid value for onboarding step [{$step}].");
    }
}
