<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;

final class HelpCommandHandler implements UpdateHandler
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $text = (string) ($update['message']['text'] ?? '');

        return str_starts_with($text, '/help');
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $chatType = (string) $message['chat']['type'];

        // Group Lock (FR-BOT-01): in group chats the bot stays silent unless
        // the chat is a whitelisted, active group. Private chats are open.
        if ($chatType !== 'private'
            && ! TelegramGroup::query()->where('chat_id', $chatId)->where('status', 'active')->exists()
        ) {
            return;
        }

        $text = $chatType === 'private'
            ? "LexiFlow — помощник для изучения английского.\n\n".
              "Для учителей: добавьте бота в группу, админ активирует её.\n".
              "Доступные команды в группе: /start_training, /start_exam, /close_exam, /help\n".
              'Личный отчёт: /stats'
            : "LexiFlow Bot в группе.\n\nКоманды учителя:\n".
              "/start_training — запустить тренировку\n".
              "/start_exam — запустить экзамен\n".
              "/close_exam — закрыть экзамен\n".
              "/stats — личный отчёт в чате с ботом\n".
              '/help — эта справка';

        $this->telegram->sendMessage($chatId, $text);
    }
}
