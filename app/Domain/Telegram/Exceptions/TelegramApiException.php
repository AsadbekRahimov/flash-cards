<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use App\Domain\Operations\Support\SecretRedactor;
use RuntimeException;
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
    public static function fromSdk(Throwable $sdk): self
    {
        return new self(
            sprintf('Telegram API request failed (%s): %s', $sdk::class, SecretRedactor::redact($sdk->getMessage())),
            is_int($sdk->getCode()) ? $sdk->getCode() : 0,
        );
    }
}
