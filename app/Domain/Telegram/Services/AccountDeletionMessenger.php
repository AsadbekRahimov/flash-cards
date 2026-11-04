<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;

/**
 * The bot side of "Delete account" (`/delete_account`, the button of `/settings`): the confirmation with two inline
 * buttons. The buttons (`del1:yes` / `del1:no`) are handled by DeleteAccountCallbackHandler; nothing is deleted before
 * the learner taps "yes". The words are in `bot.delete_account.*`.
 */
final class AccountDeletionMessenger
{
    public const PREFIX = 'del1';

    public const ASK = self::PREFIX.':ask';

    public const YES = self::PREFIX.':yes';

    public const NO = self::PREFIX.':no';

    public function __construct(private readonly TelegramClient $telegram) {}

    public function sendConfirmation(int|string $chatId, string $locale): void
    {
        $this->telegram->sendMessage(
            $chatId,
            __('bot.delete_account.confirm', ['days' => max(1, (int) config('learners.purge.after_days', 30))], $locale),
            replyMarkup: ['inline_keyboard' => [[
                ['text' => __('bot.delete_account.yes_button', [], $locale), 'callback_data' => self::YES],
                ['text' => __('bot.delete_account.no_button', [], $locale), 'callback_data' => self::NO],
            ]]],
        );
    }
}
