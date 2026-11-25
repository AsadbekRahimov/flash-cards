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
 *
 * Not final: the two answers a caller must tell apart from "some other refusal" are subclasses, so every existing
 * `catch (TelegramApiException)` keeps catching them (docs/34 §4в п. 1): {@see TelegramRateLimitedException} (429)
 * and {@see TelegramChatMigratedException} (400 with `migrate_to_chat_id`). Both are definite refusals (`isApiResponse()`).
 */
class TelegramApiException extends RuntimeException
{
    /**
     * Whether Telegram ANSWERED with a definite refusal (4xx) — the request was not carried out.
     * False for transport failures (timeout, cURL error, 5xx) and for exceptions built by hand:
     * there the request may have been delivered, so a caller must not retry a one-time post.
     */
    protected bool $apiResponse = false;

    /** Telegram's 400 "message is not modified": the edit asked for what the message already shows (docs/33 §6.3, K1). */
    protected bool $messageNotModified = false;

    public static function fromSdk(Throwable $sdk): self
    {
        $exception = new self(
            sprintf('Telegram API request failed (%s): %s', $sdk::class, SecretRedactor::redact($sdk->getMessage())),
            is_int($sdk->getCode()) ? $sdk->getCode() : 0,
        );
        $status = $sdk instanceof TelegramResponseException ? ($sdk->getHttpStatusCode() ?? 0) : 0;
        $exception->apiResponse = $status >= 400 && $status < 500;
        $exception->messageNotModified = $exception->apiResponse && self::saysNotModified($sdk->getMessage());

        return $exception;
    }

    /** A definite refusal by the Bot API (the answer was received, the action did not happen). */
    public static function apiError(string $message, int $code = 400): self
    {
        $exception = new self($message, $code);
        $exception->apiResponse = true;
        $exception->messageNotModified = self::saysNotModified($message);

        return $exception;
    }

    public function isApiResponse(): bool
    {
        return $this->apiResponse;
    }

    /**
     * The refusal of an edit whose text and keyboard are already those of the message: nothing to do, and a caller that only wants
     * the message to look so (the card of a cup, docs/33 §6.3) counts it as a success.
     */
    public function isMessageNotModified(): bool
    {
        return $this->messageNotModified;
    }

    private static function saysNotModified(string $description): bool
    {
        return str_contains(strtolower($description), 'message is not modified');
    }
}
