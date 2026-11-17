<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learners\DTO\ReminderQuizVerdict;
use App\Domain\Learners\Enums\ReminderQuizStatus;
use App\Domain\Learners\Services\ReminderQuizAnswerService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\ReminderQuizMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Domain\Telegram\Support\ReminderQuizCallback;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * A tap on an option of the reminder question (`rq1:<token>:<index>`, docs/04 B.5a, docs/12 §7.5).
 *
 * `callback_data` is not signed, so it is matched strictly and trusted for nothing: the learner is the SENDER of the tap
 * (`from.id`, which must also be the id of the private chat of the message — a button of a message anywhere else, or one
 * forwarded into another chat, is ignored), never anyone named in the data; the right option is not in the data at all.
 * {@see ReminderQuizAnswerService} compares the token with the state kept for that learner, so another person's tap
 * on a forwarded question finds no matching state and gets "outdated".
 *
 * The query is answered once, by the outcome: without text for an answer, with an alert for a question that is gone (the
 * choice buttons are then replaced by the way into the app). The message is edited into the verdict with
 * `editMessageText`; a refused edit ("message is not modified" on a double tap, a message too old to edit) is only logged
 * — the answer has been recorded, and a repeated tap shows the same verdict again.
 */
final class ReminderQuizCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CallbackAcknowledger $acknowledger,
        private readonly DmLearnerResolver $learners,
        private readonly LocaleResolver $locales,
        private readonly ReminderQuizAnswerService $answers,
        private readonly ReminderQuizMessenger $messenger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), ReminderQuizCallback::PREFIX.':');
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

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $message = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $parsed = ReminderQuizCallback::parse((string) ($cq['data'] ?? ''));

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || $parsed === null
        ) {
            $this->acknowledger->acknowledge($update);

            return;
        }

        $learner = $this->learners->resolve(is_array($cq['from'] ?? null) ? $cq['from'] : []);
        if ($learner === null || $learner->blocked_at !== null) {
            $this->acknowledger->acknowledge($update);

            return;
        }

        $locale = $this->locales->forLearner($learner);
        $messageId = (int) ($message['message_id'] ?? 0);

        // An InaccessibleMessage (Bot API 7+: `date` = 0) or a stripped one: nothing to edit, so nothing to answer either.
        if ($messageId <= 0 || (int) ($message['date'] ?? 1) === 0) {
            $this->answers->expire($learner, 'no_message');
            $this->acknowledger->acknowledge($update, $this->messenger->expiredAlert($locale));

            return;
        }

        try {
            $verdict = $this->answers->answer($learner, $parsed['token'], $parsed['index']);
        } catch (LockTimeoutException) {
            // Another tap of the same learner is being graded; the spinner is dismissed, a later tap shows the verdict.
            $this->acknowledger->acknowledge($update);

            return;
        }

        if ($verdict->status === ReminderQuizStatus::Expired) {
            $this->acknowledger->acknowledge($update, $this->messenger->expiredAlert($locale));
            $this->edit(fn () => $this->telegram->editMessageReplyMarkup($fromId, $messageId, $this->messenger->expiredMarkup($locale)));

            return;
        }

        $this->acknowledger->acknowledge($update);
        $this->showVerdict($fromId, $messageId, $verdict, $locale);
    }

    private function showVerdict(int $chatId, int $messageId, ReminderQuizVerdict $verdict, string $locale): void
    {
        $view = $this->messenger->verdict($verdict, $locale);

        $this->edit(fn () => $this->telegram->editMessageText($chatId, $messageId, $view['text'], null, $view['reply_markup']));
    }

    private function edit(callable $edit): void
    {
        try {
            $edit();
        } catch (TelegramApiException $e) {
            Log::warning('telegram.reminder_quiz_edit_failed', ['code' => $e->getCode()]);
        }
    }
}
