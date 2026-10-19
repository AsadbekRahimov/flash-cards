<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;

/**
 * Recording TelegramClient for tests: every outgoing call is kept in `$sent`,
 * `$answered` and `$edits` so a test can assert on text, buttons and order.
 */
final class FakeTelegramClient implements TelegramClient
{
    /** @var list<array{chat_id:int|string, text:string, button:string|null, url:string|null, markup:array<string, mixed>|null}> */
    public array $sent = [];

    /** @var list<string> */
    public array $answered = [];

    /** @var list<array{chat_id:int|string, message_id:int, markup:array<string, mixed>|null}> */
    public array $edits = [];

    /** When true, editMessageReplyMarkup throws like Telegram does for a deleted / unchanged message. */
    public bool $failEdits = false;

    /** When true, answerCallbackQuery throws like Telegram does for an expired query ("query is too old"). */
    public bool $failAnswers = false;

    /** The next N sendMessage / sendWebAppButton calls throw like a Bot API outage (a retried job then succeeds). */
    public int $failSends = 0;

    /** What getChatMemberStatus answers (the bot's own status in the group for the admin check). */
    public string $chatMemberStatus = 'administrator';

    /** When true, getChatMemberStatus throws like a Bot API outage / 4xx. */
    public bool $failChatMember = false;

    /** @var list<array{chat_id:int|string, user_id:int}> */
    public array $chatMemberLookups = [];

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => null, 'url' => null, 'markup' => $replyMarkup];
    }

    public function sendWebAppButton(int|string $chatId, string $text, string $buttonText, string $url, ?string $parseMode = null): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => $buttonText, 'url' => $url, 'markup' => null];
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        if ($this->failAnswers) {
            throw new TelegramApiException('query is too old and response timeout expired');
        }

        $this->answered[] = $callbackQueryId;
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        if ($this->failEdits) {
            throw new TelegramApiException('message is not modified');
        }

        $this->edits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'markup' => $replyMarkup];
    }

    public function getChatMemberStatus(int|string $chatId, int $userId): string
    {
        $this->chatMemberLookups[] = ['chat_id' => $chatId, 'user_id' => $userId];

        if ($this->failChatMember) {
            throw new TelegramApiException('Bad Request: chat not found');
        }

        return $this->chatMemberStatus;
    }

    public function setWebhook(string $url, string $secretHeader): bool
    {
        return true;
    }

    public function deleteWebhook(): bool
    {
        return true;
    }

    private function failNextSend(): void
    {
        if ($this->failSends > 0) {
            $this->failSends--;

            throw new TelegramApiException('Bad Gateway');
        }
    }

    /**
     * Callback data of every callback button of the last sent message that carried an inline keyboard.
     *
     * @return list<string>
     */
    public function lastCallbackData(): array
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            $markup = $this->sent[$i]['markup'];
            if ($markup === null) {
                continue;
            }

            $data = [];
            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && isset($button['callback_data'])) {
                        $data[] = (string) $button['callback_data'];
                    }
                }
            }

            return $data;
        }

        return [];
    }
}
