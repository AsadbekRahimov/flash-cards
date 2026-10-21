<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Contracts;

use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;

interface TelegramClient
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void;

    public function sendWebAppButton(
        int|string $chatId,
        string $text,
        string $buttonText,
        string $url,
        ?string $parseMode = null,
    ): void;

    /**
     * Sends a local PNG / JPEG file as a photo with an optional caption (docs/13 §7.4). Sent at once:
     * callers (queued DM jobs) take a slot of the bot-wide budget themselves.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramForbiddenException when the recipient cannot be reached (blocked bot / no dialog)
     * @throws TelegramApiException on any other failed Bot API call (or an unreadable file)
     */
    public function sendPhoto(
        int|string $chatId,
        string $photoPath,
        ?string $caption = null,
        ?array $replyMarkup = null,
    ): void;

    public function answerCallbackQuery(string $callbackQueryId): void;

    /**
     * Replaces the inline keyboard of an already sent message; `null` removes it.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramApiException when Telegram refuses (message deleted / unchanged)
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void;

    /**
     * Status of a user in a chat (`creator`, `administrator`, `member`, `restricted`, `left`, `kicked`).
     *
     * @throws TelegramApiException when Telegram refuses or cannot be reached
     */
    public function getChatMemberStatus(int|string $chatId, int $userId): string;

    public function setWebhook(string $url, string $secretHeader): bool;

    public function deleteWebhook(): bool;

    /**
     * Telegram Stars invoice link (`createInvoiceLink`, docs/13 §4.1). `$prices` is exactly one
     * `{label, amount}` for Stars; `$subscriptionPeriod` (seconds, only 2592000 is accepted by
     * Telegram today) turns the invoice into a subscription.
     *
     * @param  list<array{label: string, amount: int}>  $prices
     * @return string the `https://t.me/$...` link the client opens with `openInvoice`
     *
     * @throws TelegramApiException
     */
    public function createInvoiceLink(string $title, string $description, string $payload, string $currency, array $prices, ?int $subscriptionPeriod = null): string;

    /**
     * Answers a `pre_checkout_query` within Telegram's 10-second window; `$errorMessage` is shown
     * to the user when `$ok` is false.
     *
     * @throws TelegramApiException
     */
    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, ?string $errorMessage = null): void;

    /**
     * Stops (`$isCanceled = true`) or resumes a Stars subscription identified by the
     * `telegram_payment_charge_id` of its first payment.
     *
     * @throws TelegramApiException
     */
    public function editUserStarSubscription(int $userId, string $telegramPaymentChargeId, bool $isCanceled): void;

    /**
     * Returns the Stars of one payment to the user (`refundStarPayment`).
     *
     * @throws TelegramApiException
     */
    public function refundStarPayment(int $userId, string $telegramPaymentChargeId): void;

    /**
     * A page of the bot's Stars transactions (`getStarTransactions`), newest first.
     *
     * @return list<array<string, mixed>> raw `StarTransaction` objects
     *
     * @throws TelegramApiException
     */
    public function getStarTransactions(int $offset = 0, int $limit = 100): array;
}
