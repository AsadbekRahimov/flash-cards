<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use App\Domain\Telegram\Services\TelegramMessageRateLimiter;

/**
 * Telegram answered 429 Too Many Requests (flood control) on any method: the request was NOT carried out, and the Bot API
 * refuses repeats for `retryAfter` seconds (`parameters.retry_after`, core.telegram.org/bots/api#responseparameters).
 * Ignoring it makes Telegram's ban longer.
 *
 * By the time a caller sees this, the client has already paused the outbound budget ({@see TelegramMessageRateLimiter::pause()}):
 * every background send waits on its own. The caller (docs/34 §4в, "Спецификация для отправителей") removes its idempotency marker
 * — the message was definitely not delivered — and rethrows; the job middleware `ThrottleTelegramOutbound` puts the job back for
 * `retryAfter` (+ jitter) without spending `maxExceptions`.
 *
 * The message carries only the method and the number: never the SDK text (it may contain the request URL with the token).
 */
final class TelegramRateLimitedException extends TelegramApiException
{
    /** Used when a 429 comes without `parameters.retry_after` (Telegram always sends it; this is a guard, not a policy). */
    public const DEFAULT_RETRY_AFTER = 3;

    public function __construct(public readonly int $retryAfter, string $method = 'request')
    {
        parent::__construct(sprintf('Telegram API rate limit (429) on %s: retry after %d s', $method, $retryAfter), 429);
        $this->apiResponse = true;
    }
}
