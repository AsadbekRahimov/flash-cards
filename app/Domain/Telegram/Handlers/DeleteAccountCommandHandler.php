<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\AccountDeletionMessenger;
use App\Models\Learner;
use App\Models\TelegramGroup;

/**
 * `/delete_account` — asks to confirm the deletion of the account (owner's decision of 03.11.2026); the answer is
 * {@see DeleteAccountCallbackHandler}'s. A learner's own action, so a DM only: in an active group the bot points to the DM,
 * a non-active group stays silent (Group Lock). The learner is looked up WITHOUT reviving: someone whose account is
 * already deleted is told how to return, not brought back by asking to delete it.
 */
final class DeleteAccountCommandHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly AccountDeletionMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        return preg_match('~^/delete_account(?:@\w+)?(\s|$)~', (string) ($message['text'] ?? '')) === 1;
    }

    // In a DM the Group Lock does not apply; in a group only whitelisted groups get the hint.
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

        if (($message['chat']['type'] ?? '') !== 'private') {
            $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
            $locale = $group !== null ? $this->locales->forGroup($group) : $this->locales->default();
            $this->telegram->sendMessage($chatId, __('bot.delete_account.use_private', [], $locale));

            return;
        }

        $fromId = (int) ($message['from']['id'] ?? 0);
        if ($fromId <= 0) {
            return;
        }

        $learner = Learner::query()->where('telegram_user_id', $fromId)->first();
        if ($learner === null) {
            $code = $message['from']['language_code'] ?? null;
            $this->telegram->sendMessage($chatId, __('bot.delete_account.none', [], $this->locales->fromLanguageCode(is_string($code) ? $code : null)));

            return;
        }

        $locale = $this->locales->forLearner($learner);

        // A banned learner cannot shed the ban by deleting the account and coming back as a new one.
        if ($learner->blocked_at !== null) {
            $this->telegram->sendMessage($chatId, __('bot.delete_account.blocked', [], $locale));

            return;
        }

        $this->messenger->sendConfirmation($chatId, $locale);
    }
}
