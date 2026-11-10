<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\ExamSession;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Opens a group exam for a teacher and announces it in the group with the web-app button: the common tail of
 * `/start_exam <stage> <lesson> <minutes>` and of the last tap of the `/start_exam` picker. The rights of the teacher
 * are checked by {@see ExamSessionService::open()}; a refusal is explained to the group in its language.
 */
final class ExamLauncher
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ExamSessionService $exams,
        private readonly ProductEventRecorder $events,
        private readonly GroupSessionAnnouncer $announcer,
        private readonly BotSupportContact $support,
    ) {}

    public function launch(int $chatId, TelegramGroup $group, User $teacher, int $stage, int $lesson, int $minutes, string $locale): void
    {
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

    private function humanizeError(ExamSessionException $e, string $locale): string
    {
        return match ($e->reason) {
            ExamSessionException::REASON_NOT_TEACHER => __('errors.exam.not_teacher', [], $locale),
            ExamSessionException::REASON_GROUP_INACTIVE => $this->support->groupInactive($locale),
            ExamSessionException::REASON_STAGE_NOT_FOUND => __('errors.stage_not_found', [], $locale),
            ExamSessionException::REASON_LESSON_NOT_FOUND => __('errors.lesson_not_found', [], $locale),
            ExamSessionException::REASON_NOT_ENOUGH_WORDS => __('errors.exam.not_enough_words', [], $locale),
            ExamSessionException::REASON_NOT_ENOUGH_DISTRACTORS => __('errors.exam.not_enough_distractors', [], $locale),
            ExamSessionException::REASON_ALREADY_OPEN => __('errors.exam.already_open', [], $locale),
            ExamSessionException::REASON_INVALID_DURATION => __('errors.exam.invalid_duration', [], $locale),
            default => __('errors.exam.failed', [], $locale),
        };
    }

    private function postWebAppButton(int $chatId, ExamSession $session, int $stage, int $lesson, int $minutes, string $locale): void
    {
        $base = rtrim((string) config('twa.base_url'), '/');
        $seconds = (int) ($session->config['seconds_per_question'] ?? ExamSessionService::DEFAULT_SECONDS_PER_QUESTION);

        $this->announcer->announce(
            chatId: $chatId,
            text: __('exam.started', ['stage' => $stage, 'lesson' => $lesson, 'minutes' => $minutes, 'seconds' => $seconds], $locale),
            buttonText: __('exam.open_button', [], $locale),
            url: "{$base}/twa/exam/{$session->id}",
            failureText: __('errors.exam.not_delivered', [], $locale),
            cancel: fn () => $this->exams->cancelUndelivered($session),
            parseMode: 'HTML',
        );
    }
}
