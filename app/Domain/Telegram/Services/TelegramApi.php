<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Telegram\Bot\Api;

class TelegramApi
{
    private Api $api;

    public function __construct()
    {
        $this->api = new Api((string) config('telegram.bot_token'));
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup  Optional inline/reply keyboard markup.
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
