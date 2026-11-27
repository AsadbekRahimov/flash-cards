<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Cup\Support\DisplayName;
use RuntimeException;

/**
 * Why a step of the onboarding of the Mini App (docs/40 §2.1, docs/04 A.24) was refused. Nothing of the request is applied.
 *
 *   409  onboarding_completed   the onboarding is already done (in the bot, on another device): the client goes to "Today"
 *   422  onboarding_incomplete  `POST /onboarding/complete` before the level is chosen, `details = {missing: [level]}`
 *   422  display_name_invalid   the name breaks a rule of {@see DisplayName}, `details = {reason}` (`DisplayName::REASON_*`)
 *   410  placement_expired      the placement test (docs/40 §2.1a) ran out or was never started: the client starts a new one
 *   422  placement_unavailable  the catalog cannot give a placement test (fewer than two levels with words)
 */
final class TwaOnboardingException extends RuntimeException
{
    public const COMPLETED = 'onboarding_completed';

    public const INCOMPLETE = 'onboarding_incomplete';

    public const DISPLAY_NAME_INVALID = 'display_name_invalid';

    public const PLACEMENT_EXPIRED = 'placement_expired';

    public const PLACEMENT_UNAVAILABLE = 'placement_unavailable';

    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED, 409, 'The onboarding is already completed.');
    }

    /** @param  list<string>  $missing */
    public static function incomplete(array $missing): self
    {
        return new self(self::INCOMPLETE, 422, 'The onboarding is not finished yet.', ['missing' => $missing]);
    }

    /** 410: the placement test of the learner has run out (docs/40 §2.1a) or was never issued - a new one is started. */
    public static function placementExpired(): self
    {
        return new self(self::PLACEMENT_EXPIRED, 410, 'The placement test has expired. Start a new one.');
    }

    /** 422: the catalog cannot give the questions of the placement test (fewer than two levels with words). */
    public static function placementUnavailable(): self
    {
        return new self(self::PLACEMENT_UNAVAILABLE, 422, 'The placement test is not available.');
    }

    public static function displayNameInvalid(string $reason): self
    {
        return new self(self::DISPLAY_NAME_INVALID, 422, match ($reason) {
            DisplayName::REASON_EMPTY, DisplayName::REASON_TOO_LONG => 'The name must be 1 to 32 characters.',
            DisplayName::REASON_MARKS => 'The name has too many combining marks.',
            default => 'The name must not look like a link, a @mention, a /command or a #tag.',
        }, ['reason' => $reason]);
    }
}
