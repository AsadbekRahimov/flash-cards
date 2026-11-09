<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Game\Services\GameAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\GroupUpdateHandler;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;

final class HelpCommandHandler implements GroupUpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly GameAccess $game,
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
    public function handle(array $update, ?TelegramGroup $group = null): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId = (int) $message['chat']['id'];
        $chatType = (string) $message['chat']['type'];

        if ($chatType === 'private') {
            // Anyone may ask for help in a DM: a learner gets the language of their LexiFlow profile (like /today, /settings),
            // a stranger the language of Telegram. The teacher block is for active teachers only.
            $fromId = (int) ($message['from']['id'] ?? 0);
            $learner = $fromId > 0 ? Learner::query()->where('telegram_user_id', $fromId)->first() : null;

            if ($learner !== null) {
                $locale = $this->locales->forLearner($learner);
            } else {
                $languageCode = $message['from']['language_code'] ?? null;
                $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
            }

            $text = __('bot.help.private', [], $locale);

            $isTeacher = $fromId > 0 && User::query()->where('telegram_user_id', $fromId)->where('is_active', true)->exists();
            if ($isTeacher) {
                $caravan = $this->game->classCaravanOpen() ? __('bot.help.private_caravan', [], $locale) : '';
                $text .= "\n\n".__('bot.help.private_teacher', ['caravan' => $caravan], $locale);
            }
        } else {
            $group ??= TelegramGroup::query()->where('chat_id', $chatId)->first();
            $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
            // The caravan commands are listed only with GAME_ACCESS=all (G2.9b, G2.13): off and pilot hide them everywhere.
            $caravan = ! $this->game->classCaravanOpen() ? '' : __('bot.help.group_caravan', [], $locale);
            $text = __('bot.help.group', ['caravan' => $caravan], $locale);
        }

        $this->telegram->sendMessage($chatId, $text);
    }
}
