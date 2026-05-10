<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use Telegram\Bot\Api;

class IrazasyedTelegramClient implements TelegramClient
{
    public function __construct(
        private readonly Api $api,
        private readonly TelegramKeyboardFactory $keyboards,
    ) {}

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $this->api->sendMessage($params);
    }

    public function sendWebAppButton(
        int|string $chatId,
        string $text,
        string $buttonText,
        string $url,
        ?string $parseMode = null,
    ): void {
        $this->sendMessage(
            chatId: $chatId,
            text: $text,
            parseMode: $parseMode,
            replyMarkup: $this->keyboards->webAppButton($buttonText, $url),
        );
    }

    public function setWebhook(string $url, string $secretHeader): bool
    {
        return (bool) $this->api->setWebhook([
            'url' => $url,
            'secret_token' => $secretHeader,
            'allowed_updates' => ['message', 'my_chat_member', 'chat_member'],
        ]);
    }

    public function deleteWebhook(): bool
    {
        return (bool) $this->api->removeWebhook();
    }
}
