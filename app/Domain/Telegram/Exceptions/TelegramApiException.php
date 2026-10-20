<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use App\Domain\Operations\Support\SecretRedactor;
use RuntimeException;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Throwable;

/**
 * A Bot API call failed (network error, timeout, non-2xx answer other than 403).
 *
 * Replaces the SDK/Guzzle exception at the TelegramClient boundary. Their
 * messages embed the request URL with the bot token
 * (`cURL error 28: … for https://api.telegram.org/bot<TOKEN>/sendMessage`), and
 * Laravel writes `(string) $exception` (including the `previous` chain) to
 * laravel.log and `failed_jobs.exception`. So this exception carries only the
 * redacted message, the original class and code, and deliberately NO `previous`.
 */
final class TelegramApiException extends RuntimeException
{
    /**
     * Whether Telegram ANSWERED with a definite refusal (4xx) — the request was not carried out.
     * False for transport failures (timeout, cURL error, 5xx) and for exceptions built by hand:
     * there the request may have been delivered, so a caller must not retry a one-time post.
     */
    private bool $apiResponse = false;

    public static function fromSdk(Throwable $sdk): self
    {
        $exception = new self(
            sprintf('Telegram API request failed (%s): %s', $sdk::class, SecretRedactor::redact($sdk->getMessage())),
            is_int($sdk->getCode()) ? $sdk->getCode() : 0,
        );
        $status = $sdk instanceof TelegramResponseException ? ($sdk->getHttpStatusCode() ?? 0) : 0;
        $exception->apiResponse = $status >= 400 && $status < 500;

        return $exception;
    }

    /** A definite refusal by the Bot API (the answer was received, the action did not happen). */
    public static function apiError(string $message, int $code = 400): self
    {
        $exception = new self($message, $code);
        $exception->apiResponse = true;

        return $exception;
    }

    public function isApiResponse(): bool
    {
        return $this->apiResponse;
    }
}
