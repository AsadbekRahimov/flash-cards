<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Polls\DTO\PollTapResult;
use App\Domain\Polls\Enums\PollTapStatus;
use App\Domain\Polls\Services\PollViewCache;
use App\Domain\Polls\Services\PollVoteService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\PollMessenger;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Domain\Telegram\Support\PollCallback;
use App\Domain\Telegram\Support\PollMessageEdit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * A tap on a poll button `pl1:<token>:<n|s|c>` (docs/04 B.5b, docs/35 §1.5, O5).
 *
 * `callback_data` is not signed: it is matched strictly ({@see PollCallback}) and trusted for nothing. The tap counts only from the
 * private chat of the message (`message.chat.id = from.id`); a group, a forwarded copy, someone else's tap, a stripped message
 * (`InaccessibleMessage`, `date = 0`) get the alert «Опрос недоступен» and nothing is edited. Whether the tapper may answer at all is
 * decided by {@see PollVoteService::tap()}: `from.id` must be the delivery's Telegram id, and every refusal looks the same.
 *
 * The verdict turns into one answer of the query and at most one edit of the message:
 *  - `Voted` / `Repeated` / `Locked` - the question and "Спасибо" only (docs/31 §5г), «Изменить ответ» under it when allowed
 *    (`Locked` also says the answer cannot be changed); `Repeated` heals an edit lost on the way;
 *  - `Changing` - the question with the options again, the current answer ticked (several answers);
 *  - `Toggled` - only the keyboard (☐/☑);
 *  - `EmptySubmit`, `LimitReached` - a short toast, nothing edited;
 *  - `Closed` - the alert «Опрос завершён», the buttons go; `Unavailable` - the alert «Опрос недоступен».
 * A photo gets `editMessageCaption`, a text `editMessageText`. A refused edit is only logged ("message is not modified" - a double
 * tap - not even that): the answer is recorded, the next tap shows it again.
 *
 * Statements: only those of {@see PollVoteService::tap()} (1-5) - the texts and the keyboard come from {@see PollViewCache} (2 reads
 * when the entry is missing) - plus the event `poll.answered` on a real (not test) `Voted`. The language of the texts is the
 * delivery's; for a refusal before the delivery is known, the tapper's Telegram language.
 */
final class PollCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CallbackAcknowledger $acknowledger,
        private readonly PollVoteService $votes,
        private readonly PollViewCache $views,
        private readonly PollMessenger $messenger,
        private readonly ProductEventRecorder $events,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        return str_starts_with((string) ($update['callback_query']['data'] ?? ''), PollCallback::PREFIX.':');
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
        $parsed = PollCallback::parse((string) ($cq['data'] ?? ''));
        $tapperLocale = str_starts_with(strtolower((string) ($cq['from']['language_code'] ?? '')), 'uz') ? 'uz' : 'ru';
        $messageId = (int) ($message['message_id'] ?? 0);

        if (($message['chat']['type'] ?? '') !== 'private'
            || $fromId <= 0
            || (int) ($message['chat']['id'] ?? 0) !== $fromId
            || $parsed === null
            // An InaccessibleMessage (Bot API 7+: `date` = 0) or a stripped one: there is nothing to edit.
            || $messageId <= 0
            || (int) ($message['date'] ?? 1) === 0
        ) {
            $this->acknowledger->acknowledge($update, $this->messenger->notice('unavailable', $tapperLocale));

            return;
        }

        $result = $this->votes->tap($parsed['token'], $fromId, $parsed['action'], CarbonImmutable::now());
        $locale = PollMessenger::locale($result->locale ?? $tapperLocale);
        $edit = new PollMessageEdit($fromId, $messageId, ! empty($message['photo']), $parsed['token'], $locale);

        match ($result->status) {
            PollTapStatus::Unavailable => $this->acknowledger->acknowledge($update, $this->messenger->notice('unavailable', $locale)),
            PollTapStatus::Closed => $this->closed($update, $edit),
            PollTapStatus::Voted => $this->voted($update, $result, $edit),
            PollTapStatus::Repeated => $this->answered($update, $result, $edit, null),
            PollTapStatus::Locked => $this->answered($update, $result, $edit, $this->messenger->notice('locked', $locale)),
            PollTapStatus::Changing => $this->changing($update, $result, $edit),
            PollTapStatus::Toggled => $this->toggled($update, $result, $edit),
            PollTapStatus::LimitReached => $this->limitReached($update, $result, $locale),
            PollTapStatus::EmptySubmit => $this->toast($update, $this->messenger->notice('empty', $locale)),
        };
    }

    /** @param array<string, mixed> $update */
    private function closed(array $update, PollMessageEdit $edit): void
    {
        $this->acknowledger->acknowledge($update, $this->messenger->notice('closed', $edit->locale));
        $this->edit(fn () => $this->telegram->editMessageReplyMarkup($edit->chatId, $edit->messageId, null));
    }

    /** @param array<string, mixed> $update */
    private function voted(array $update, PollTapResult $result, PollMessageEdit $edit): void
    {
        if (! $result->isTest && $result->pollId !== null) {
            $this->events->record(ProductEventName::PollAnswered, [
                'poll_id' => $result->pollId,
                'delivery_id' => $result->deliveryId,
                'changed' => $result->changed,
            ]);
        }

        $this->answered($update, $result, $edit, null);
    }

    /** @param array<string, mixed> $update */
    private function answered(array $update, PollTapResult $result, PollMessageEdit $edit, ?string $toast): void
    {
        $toast === null ? $this->acknowledger->acknowledge($update) : $this->toast($update, $toast);

        $view = $result->pollId === null ? null : $this->views->get($result->pollId, $result->pollVersion);
        if ($view === null) {
            return;
        }

        [$poll] = PollViewCache::models($view);
        $limit = $edit->photo ? PollMessenger::CAPTION_LIMIT : PollMessenger::TEXT_LIMIT;

        $this->show(
            $edit,
            $this->messenger->answered($poll, $edit->locale, $limit),
            $this->messenger->answeredKeyboard($edit->token, $edit->locale, $edit->chatId, $result->allowChange),
        );
    }

    /** @param array<string, mixed> $update */
    private function changing(array $update, PollTapResult $result, PollMessageEdit $edit): void
    {
        $this->acknowledger->acknowledge($update);

        $view = $result->pollId === null ? null : $this->views->get($result->pollId, $result->pollVersion);
        if ($view === null) {
            return;
        }

        [$poll, $options] = PollViewCache::models($view);

        $this->show(
            $edit,
            $this->messenger->text($poll, $options, $edit->locale),
            $this->messenger->keyboard($poll, $options, $edit->token, $edit->locale, $edit->chatId, $result->selectedOptionIds),
        );
    }

    /** @param array<string, mixed> $update */
    private function toggled(array $update, PollTapResult $result, PollMessageEdit $edit): void
    {
        $this->acknowledger->acknowledge($update);

        $view = $result->pollId === null ? null : $this->views->get($result->pollId, $result->pollVersion);
        if ($view === null) {
            return;
        }

        [$poll, $options] = PollViewCache::models($view);
        $keyboard = $this->messenger->keyboard($poll, $options, $edit->token, $edit->locale, $edit->chatId, $result->selectedOptionIds);

        $this->edit(fn () => $this->telegram->editMessageReplyMarkup($edit->chatId, $edit->messageId, $keyboard));
    }

    /** @param array<string, mixed> $update */
    private function limitReached(array $update, PollTapResult $result, string $locale): void
    {
        $view = $result->pollId === null ? null : $this->views->get($result->pollId, $result->pollVersion);
        $max = $view['max_choices'] ?? count($view['options'] ?? []);

        $this->toast($update, $this->messenger->notice('limit', $locale, ['max' => (int) $max]));
    }

    /** @param array<string, mixed>|null $keyboard */
    private function show(PollMessageEdit $edit, string $text, ?array $keyboard): void
    {
        $this->edit(fn () => $edit->photo
            ? $this->telegram->editMessageCaption($edit->chatId, $edit->messageId, $text, $keyboard)
            : $this->telegram->editMessageText($edit->chatId, $edit->messageId, $text, null, $keyboard));
    }

    /**
     * A short notice at the top of the chat, not an alert to dismiss (the alerts are the refusals, {@see CallbackAcknowledger}).
     *
     * @param  array<string, mixed>  $update
     */
    private function toast(array $update, string $text): void
    {
        $id = (string) ($update['callback_query']['id'] ?? '');
        if ($id === '') {
            return;
        }

        try {
            $this->telegram->answerCallbackQuery($id, mb_substr($text, 0, 200));
        } catch (TelegramApiException $e) {
            Log::warning('telegram.callback_ack_failed', ['code' => $e->getCode()]);
        }
    }

    private function edit(callable $edit): void
    {
        try {
            $edit();
        } catch (TelegramApiException $e) {
            // A double tap asks for the same text and buttons again: not an error.
            if (! $e->isMessageNotModified()) {
                Log::warning('telegram.poll_edit_failed', ['code' => $e->getCode()]);
            }
        }
    }
}
