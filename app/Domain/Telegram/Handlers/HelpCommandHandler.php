<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Game\Services\GameAccess;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\GroupUpdateHandler;
use App\Domain\Telegram\Services\BotSupportContact;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;

final class HelpCommandHandler implements GroupUpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly GameAccess $game,
        private readonly BotSupportContact $support,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $text = (string) ($update['message']['text'] ?? '');

        // `/help`, `/help@BotName`, `/help something` — but not `/helpme`.
        return preg_match('~^/help(?:@\w+)?(\s|$)~', $text) === 1;
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
        $parseMode = null;

        if ($chatType === 'private') {
            $fromId = (int) ($message['from']['id'] ?? 0);
            $languageCode = $message['from']['language_code'] ?? null;
            // The language the learner has chosen; for anybody else (a teacher, a person who never started) the Telegram one.
            $learner = $fromId > 0 ? Learner::query()->where('telegram_user_id', $fromId)->first() : null;
            $locale = $learner !== null
                ? $this->locales->forLearner($learner)
                : $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);
            $text = $fromId > 0 && User::query()->where('telegram_user_id', $fromId)->where('is_active', true)->exists()
                ? __('bot.help.private_teacher', ['caravan' => $this->caravan('bot.help.private_caravan', $locale)], $locale)
                : $this->learnerHelp($locale);
        } else {
            $group ??= TelegramGroup::query()->where('chat_id', $chatId)->first();
            $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
            // The caravan commands are listed only with GAME_ACCESS=all (G2.9b, G2.13): off and pilot hide them everywhere.
            $text = __('bot.help.group', ['caravan' => $this->caravan('bot.help.group_caravan', $locale), 'chat_id' => $chatId], $locale);
            $parseMode = 'HTML';
        }

        $this->telegram->sendMessage($chatId, $text, $parseMode);
    }

    /** The commands of a learner; teachers and owners of centers are pointed to support (when there is a contact to point to). */
    private function learnerHelp(string $locale): string
    {
        $text = __('bot.help.private', [], $locale);
        $contact = $this->support->label();

        return $contact === null ? $text : $text."\n\n".__('bot.help.teacher_hint', ['contact' => $contact], $locale);
    }

    private function caravan(string $key, string $locale): string
    {
        return $this->game->classCaravanOpen() ? __($key, [], $locale) : '';
    }
}
