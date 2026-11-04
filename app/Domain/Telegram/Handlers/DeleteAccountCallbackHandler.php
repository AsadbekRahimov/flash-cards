<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\AccountDeletionMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Models\Learner;
use Illuminate\Support\Facades\Log;

/**
 * Buttons of the account deletion (`del1:ask|yes|no`, see {@see AccountDeletionMessenger}): `ask` is the button of
 * `/settings`, `yes` deletes, `no` cancels. `callback_data` is not signed and carries nothing: the learner is the
 * sender, looked up WITHOUT reviving (a second tap on "yes" finds nobody and does nothing). A button of a message in
 * any chat but the sender's own DM, and a blocked learner, are ignored. The buttons are removed once answered.
 */
final class DeleteAccountCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CallbackAcknowledger $acknowledger,
        private readonly LocaleResolver $locales,
        private readonly LearnerDeletionService $deletion,
        private readonly AccountDeletionMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), AccountDeletionMessenger::PREFIX.':');
    }

    // Private-chat only; the Group Lock does not apply.
    public function requiresActiveGroup(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];

        $this->acknowledger->acknowledge($update);

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $data = (string) ($cq['data'] ?? '');

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || ! in_array($data, [AccountDeletionMessenger::ASK, AccountDeletionMessenger::YES, AccountDeletionMessenger::NO], true)
        ) {
            return;
        }

        $learner = Learner::query()->where('telegram_user_id', $fromId)->first();
        if ($learner === null || $learner->blocked_at !== null) {
            return;
        }

        $locale = $this->locales->forLearner($learner);

        if ($data === AccountDeletionMessenger::ASK) {
            $this->messenger->sendConfirmation($fromId, $locale);

            return;
        }

        $this->removeButtons($fromId, (int) ($message['message_id'] ?? 0));

        if ($data === AccountDeletionMessenger::NO) {
            $this->telegram->sendMessage($fromId, __('bot.delete_account.canceled', [], $locale));

            return;
        }

        if ($this->deletion->delete($learner)) {
            $this->telegram->sendMessage($fromId, __('bot.delete_account.done', [
                'days' => max(1, (int) config('learners.purge.after_days', 30)),
            ], $locale));
        }
    }

    private function removeButtons(int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        try {
            $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
        } catch (TelegramApiException $e) {
            // Cosmetic: an old or already edited message must not stop the deletion.
            Log::warning('telegram.delete_account_buttons_failed', ['code' => $e->getCode()]);
        }
    }
}
