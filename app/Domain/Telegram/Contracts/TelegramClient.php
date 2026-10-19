<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Contracts;

use App\Domain\Telegram\Exceptions\TelegramApiException;

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

    public function answerCallbackQuery(string $callbackQueryId): void;

    /**
     * Replaces the inline keyboard of an already sent message; `null` removes it.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramApiException when Telegram refuses (message deleted / unchanged)
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void;

    public function setWebhook(string $url, string $secretHeader): bool;

    public function deleteWebhook(): bool;
}
