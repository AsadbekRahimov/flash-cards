<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\User;

final class StartCommandHandler implements UpdateHandler
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return $chatType === 'private' && str_starts_with($text, '/start');
    }

    // Private-chat only command; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $from */
        $from = $message['from'] ?? [];
        $tgUserId = isset($from['id']) ? (int) $from['id'] : 0;
        $chatId = (int) $message['chat']['id'];

        if ($tgUserId === 0) {
            return;
        }

        $user = User::where('telegram_user_id', $tgUserId)->first();

        if ($user === null) {
            $this->telegram->sendMessage(
                $chatId,
                "Этот Telegram-аккаунт не привязан к учителю в LexiFlow. Попросите админа прописать ваш Telegram ID: {$tgUserId}.",
            );

            return;
        }

        $user->update(['last_login_at' => now()]);

        $this->telegram->sendMessage(
            $chatId,
            "Привет, {$user->name}! Вы авторизованы как учитель LexiFlow. Команды в группе: /help",
        );
    }
}
