<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Telegram\Bot\Api;

final class TelegramApi
{
    private Api $api;

    public function __construct()
    {
        $this->api = new Api((string) config('telegram.bot_token'));
    }

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        $this->api->sendMessage($params);
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
