<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;

final class HelpCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $text = (string) ($update['message']['text'] ?? '');

        return str_starts_with($text, '/help');
    }

    // Group Lock is enforced centrally by the dispatcher: /help only answers in
    // active groups, but private chats bypass the check (see dispatcher).
    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $chatType = (string) $message['chat']['type'];

        if ($chatType === 'private') {
            // Anyone may ask for help in a DM: follow their Telegram language.
            $languageCode = $message['from']['language_code'] ?? null;
            $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
            $text = __('bot.help.private', [], $locale);
        } else {
            $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
            $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
            $text = __('bot.help.group', [], $locale);
        }

        $this->telegram->sendMessage($chatId, $text);
    }
}
