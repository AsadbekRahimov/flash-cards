<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Handlers\Contracts\GroupUpdateHandler;
use App\Domain\Telegram\Services\ExamLauncher;
use App\Domain\Telegram\Support\CallbackAcknowledger;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * The two taps of the `/start_exam` picker (StartExamHandler):
 *   `estart:<stage>:<lesson>`            — a lesson is chosen: the duration row «5 / 10 / 15 min» follows;
 *   `estart:<stage>:<lesson>:<minutes>`  — the duration is chosen: the exam opens.
 * Anybody can press a button in a group, so both taps are checked against the group's teachers (the second one
 * again inside ExamSessionService::open()). The chosen lesson / duration stay in the callback data, nothing is stored.
 */
final class StartExamCallbackHandler implements GroupUpdateHandler
{
    /** The duration choices, minutes. */
    public const DURATIONS = [5, 10, 15];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly ExamLauncher $launcher,
        private readonly TeacherAuthority $authority,
        private readonly CallbackAcknowledger $acknowledger,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $data = (string) ($update['callback_query']['data'] ?? '');

        return str_starts_with($data, StartExamHandler::CALLBACK_PREFIX);
    }

    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update, ?TelegramGroup $group = null): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];

        // The spinner is dismissed on every path, but a query is answered once: a tap of somebody who is not the teacher
        // gets the refusal as an alert for them alone (a message into the group would be posted on every tap).
        $ack = fn (?string $alert = null) => $this->acknowledger->acknowledge($update, $alert);

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);
        $messageId = (int) ($cq['message']['message_id'] ?? 0);

        if ($fromId === 0 || $chatId === 0) {
            $ack();

            return;
        }

        $parts = explode(':', substr((string) ($cq['data'] ?? ''), strlen(StartExamHandler::CALLBACK_PREFIX)));
        if (! in_array(count($parts), [2, 3], true) || array_filter($parts, fn (string $p): bool => ! ctype_digit($p)) !== []) {
            $ack();

            return;
        }

        [$stage, $lesson] = [(int) $parts[0], (int) $parts[1]];
        $minutes = isset($parts[2]) ? (int) $parts[2] : null;

        /** @var TelegramGroup|null $group */
        $group ??= TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            $ack();

            return;
        }

        $locale = $this->locales->forGroup($group);

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $ack(__('errors.teacher_not_linked', [], $locale));

            return;
        }

        if (! $this->authority->canTeach($teacher, $group)) {
            $ack(__('errors.exam.not_teacher', [], $locale));

            return;
        }

        $ack();

        if ($minutes === null) {
            $this->askDuration($chatId, $messageId, $stage, $lesson, $locale);

            return;
        }

        $this->clearKeyboard($chatId, $messageId);
        $this->launcher->launch($chatId, $group, $teacher, $stage, $lesson, $minutes, $locale);
    }

    /** Takes the buttons away from the answered message so it is not used twice. */
    private function clearKeyboard(int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        try {
            $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
        } catch (TelegramApiException) {
            // Deleted, too old or already without buttons.
        }
    }

    private function askDuration(int $chatId, int $messageId, int $stage, int $lesson, string $locale): void
    {
        $this->clearKeyboard($chatId, $messageId);

        $buttons = array_map(
            fn (int $minutes): array => [
                'text' => __('exam.picker.minutes_button', ['minutes' => $minutes], $locale),
                'callback_data' => StartExamHandler::CALLBACK_PREFIX."{$stage}:{$lesson}:{$minutes}",
            ],
            self::DURATIONS,
        );

        $this->telegram->sendMessage(
            chatId: $chatId,
            text: __('exam.picker.duration_prompt', ['stage' => $stage, 'lesson' => $lesson], $locale),
            replyMarkup: ['inline_keyboard' => [$buttons]],
        );
    }
}
