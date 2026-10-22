<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\GroupSessionAnnouncer;
use App\Models\ExamSession;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Handles `/start_exam [stage] [lesson] [minutes]` from a teacher in a group.
 *
 * Defaults: stage=1, lesson=1, minutes=10.
 */
final class StartExamHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ExamSessionService $exams,
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
        private readonly GroupSessionAnnouncer $announcer,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return false;
        }

        $chatType = (string) ($message['chat']['type'] ?? '');
        $text = (string) ($message['text'] ?? '');

        return in_array($chatType, ['group', 'supergroup'], true)
            && preg_match('~^/start_exam(?:@\w+)?(\s|$)~', $text) === 1;
    }

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
        $fromId = (int) ($message['from']['id'] ?? 0);
        $text = (string) ($message['text'] ?? '');

        if ($fromId === 0) {
            return;
        }

        /** @var TelegramGroup|null $group */
        $group = TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        $locale = $this->locales->forGroup($group);

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, __('errors.teacher_not_linked', [], $locale));

            return;
        }

        [$stage, $lesson, $minutes] = $this->parseArgs($text);

        try {
            $session = $this->exams->open($group, $stage, $lesson, $minutes, $teacher);
        } catch (ExamSessionException $e) {
            $this->telegram->sendMessage($chatId, $this->humanizeError($e, $locale));

            return;
        }

        $this->events->record(
            ProductEventName::ExamStarted,
            [
                'exam_session_id' => $session->id,
                'stage' => $stage,
                'lesson' => $lesson,
                'minutes' => $minutes,
            ],
            group: $group,
            user: $teacher,
        );

        $this->postWebAppButton($chatId, $session, $stage, $lesson, $minutes, $locale);
    }

    /**
     * @return array{0:int, 1:int, 2:int}
     */
    private function parseArgs(string $text): array
    {
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2], 10];
        }
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], 1, 10];
        }

        return [1, 1, 10];
    }

    private function humanizeError(ExamSessionException $e, string $locale): string
    {
        return match ($e->reason) {
            ExamSessionException::REASON_NOT_TEACHER => __('errors.exam.not_teacher', [], $locale),
            ExamSessionException::REASON_GROUP_INACTIVE => __('errors.group_inactive', [], $locale),
            ExamSessionException::REASON_STAGE_NOT_FOUND => __('errors.stage_not_found', [], $locale),
            ExamSessionException::REASON_LESSON_NOT_FOUND => __('errors.lesson_not_found', [], $locale),
            ExamSessionException::REASON_NOT_ENOUGH_WORDS => __('errors.exam.not_enough_words', [], $locale),
            ExamSessionException::REASON_NOT_ENOUGH_DISTRACTORS => __('errors.exam.not_enough_distractors', [], $locale),
            ExamSessionException::REASON_ALREADY_OPEN => __('errors.exam.already_open', [], $locale),
            ExamSessionException::REASON_INVALID_DURATION => __('errors.exam.invalid_duration', [], $locale),
            default => __('errors.exam.failed', [], $locale),
        };
    }

    private function postWebAppButton(
        int $chatId,
        ExamSession $session,
        int $stage,
        int $lesson,
        int $minutes,
        string $locale,
    ): void {
        $base = rtrim((string) config('twa.base_url'), '/');

        $this->announcer->announce(
            chatId: $chatId,
            text: __('exam.started', ['stage' => $stage, 'lesson' => $lesson, 'minutes' => $minutes], $locale),
            buttonText: __('exam.open_button', [], $locale),
            url: "{$base}/twa/exam/{$session->id}",
            failureText: __('errors.exam.not_delivered', [], $locale),
            cancel: fn () => $this->exams->cancelUndelivered($session),
            parseMode: 'HTML',
        );
    }
}
