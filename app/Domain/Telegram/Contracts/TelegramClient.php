<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Contracts;

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

    public function setWebhook(string $url, string $secretHeader): bool;

    public function deleteWebhook(): bool;
}
