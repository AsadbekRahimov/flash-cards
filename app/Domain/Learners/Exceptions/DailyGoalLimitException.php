<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\DailyGoalLimiter;
use RuntimeException;

/**
 * A daily goal above the limit of the learner's plan (docs/34 §2б.3; Free: `billing.plans.free.limits.daily_goal_max`), refused by
 * {@see DailyGoalLimiter::assertAllowed()}. HTTP: 403 `daily_goal_pro_required` with `details = {max}`, like
 * `practice_limit_reached`; the Mini App opens the paywall `daily_goal`. Nothing of the request is applied.
 */
final class DailyGoalLimitException extends RuntimeException
{
    public const ERROR_CODE = 'daily_goal_pro_required';

    public readonly string $errorCode;

    /** @var array{max: int} */
    public readonly array $details;

    public function __construct(public readonly int $goal, public readonly int $max)
    {
        $this->errorCode = self::ERROR_CODE;
        $this->details = ['max' => $max];

        parent::__construct("A daily goal of {$goal} cards needs Pro; the plan allows at most {$max}.");
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
