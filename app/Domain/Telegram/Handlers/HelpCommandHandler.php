<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TelegramApi;

final class HelpCommandHandler implements UpdateHandler
{
    public function __construct(private readonly TelegramApi $api)
    {
    }

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

        $text = $chatType === 'private'
            ? "LexiFlow — помощник для изучения английского.\n\n".
              "Для учителей: добавьте бота в группу, админ активирует её.\n".
              "Доступные команды в группе: /start_training, /start_exam, /help"
            : "LexiFlow Bot в группе.\n\nКоманды учителя:\n".
              "/start_training — запустить тренировку\n".
              "/start_exam — запустить экзамен\n".
              "/help — эта справка";

        $this->api->sendMessage($chatId, $text);
    }
}
