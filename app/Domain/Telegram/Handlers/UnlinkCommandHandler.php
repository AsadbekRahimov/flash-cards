<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TelegramAccountLinkService;

/**
 * `/unlink` in a DM (docs/13 §6.2, review W2 of the audit 2026-11-10): the owner of the Telegram account disconnects it from the
 * staff user that holds it — the way out when somebody else's link was opened by mistake, or when a hand-typed id of `/admin`
 * points at a learner. Asks "Disconnect from the cabinet (account X)?" with Yes / No ({@see AccountLinkCallbackHandler}); an
 * account no staff user holds is told so. Not a learner's command (it is listed in the teacher's /help, not in the menu).
 */
final class UnlinkCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly TelegramAccountLinkService $accountLinks,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return ($message['chat']['type'] ?? '') === 'private'
            && preg_match('~^/unlink(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
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
        $chatId = (int) $message['chat']['id'];
        $fromId = (int) ($message['from']['id'] ?? 0);
        $languageCode = $message['from']['language_code'] ?? null;
        $locale = $this->locales->fromLanguageCode(is_string($languageCode) ? $languageCode : null);

        if ($fromId <= 0) {
            return;
        }

        $holder = $this->accountLinks->holderOf($fromId);

        if ($holder === null) {
            $this->telegram->sendMessage($chatId, __('bot.link.unlink_none', [], $locale));

            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            __('bot.link.unlink_confirm', ['name' => $holder->name], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('bot.link.unlink_yes', [], $locale), 'callback_data' => AccountLinkCallbackHandler::PREFIX.':unlinkyes'],
                ['text' => __('bot.link.unlink_no', [], $locale), 'callback_data' => AccountLinkCallbackHandler::PREFIX.':unlinkno'],
            ]]],
        );
    }
}
