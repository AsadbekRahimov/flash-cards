<?php

declare(strict_types=1);

namespace App\Domain\Learners\Exceptions;

use App\Domain\Learners\Services\ProEmojiService;
use RuntimeException;

/**
 * A Free learner chose an emoji of Pro (docs/31 §5а), refused by {@see ProEmojiService::assertAllowed()}. HTTP: 403
 * `pro_emoji_pro_required`, like `daily_goal_pro_required`; the Mini App opens the paywall. Nothing of the request is applied.
 */
final class ProEmojiProRequiredException extends RuntimeException
{
    public const ERROR_CODE = 'pro_emoji_pro_required';

    public readonly string $errorCode;

    public function __construct()
    {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct('The emoji next to the name is a Pro feature.');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
