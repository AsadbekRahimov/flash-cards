<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Listeners\Organizations\RunsDmWithoutRetryOnSyncQueue;

/**
 * A background sender that sends SEVERAL messages in one run found no slot of the bot-wide budget for the next one
 * ({@see TelegramMessageRateLimiter::awaitGlobal()} gave none within the wait): nothing was sent for that message, its marker was not
 * written. Thrown out of the handler; {@see ThrottleTelegramOutbound} puts the job back for `retryAfter` (already jittered) without spending
 * `maxExceptions`, like a job that got no slot before it started, and the retry skips the messages already sent (their markers).
 *
 * A {@see TelegramApiException} that is not an API response, so the `sync` queue (tests, local) logs and drops it like any other
 * failed DM ({@see RunsDmWithoutRetryOnSyncQueue}).
 */
final class TelegramOutboundBusyException extends TelegramApiException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(sprintf('No slot of the bot-wide Telegram budget: retry after %d s', $retryAfter));
    }
}
