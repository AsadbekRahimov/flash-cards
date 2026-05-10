<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\ExamSession;
use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Handles `/start_exam [stage] [lesson] [minutes]` from a teacher in a group.
 *
 * Defaults: stage=1, lesson=1, minutes=2.
 */
final class StartExamHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ExamSessionService $exams,
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

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, 'Ваш Telegram-аккаунт не привязан к учителю.');

            return;
        }

        [$stage, $lesson, $minutes] = $this->parseArgs($text);

        try {
            $session = $this->exams->open($group, $stage, $lesson, $minutes, $teacher);
        } catch (ExamSessionException $e) {
            $this->telegram->sendMessage($chatId, $this->humanizeError($e));

            return;
        }

        $this->postWebAppButton($chatId, $session, $stage, $lesson, $minutes);
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
            return [(int) $m[1], (int) $m[2], 2];
        }
        if (preg_match('~^/start_exam(?:@\w+)?\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], 1, 2];
        }

        return [1, 1, 2];
    }

    private function humanizeError(ExamSessionException $e): string
    {
        return match ($e->reason) {
            ExamSessionException::REASON_NOT_TEACHER => 'Только учитель этой группы может запускать экзамен.',
            ExamSessionException::REASON_GROUP_INACTIVE => 'Группа не активирована.',
            ExamSessionException::REASON_STAGE_NOT_FOUND => 'Указанный stage не найден.',
            ExamSessionException::REASON_LESSON_NOT_FOUND => 'Урок не найден.',
            ExamSessionException::REASON_NOT_ENOUGH_WORDS => 'В уроке недостаточно слов для экзамена.',
            ExamSessionException::REASON_ALREADY_OPEN => 'В группе уже идёт экзамен. Завершите его через /close_exam.',
            ExamSessionException::REASON_INVALID_DURATION => 'Длительность экзамена должна быть от 1 до 30 минут.',
            default => 'Не удалось запустить экзамен.',
        };
    }

    private function postWebAppButton(
        int $chatId,
        ExamSession $session,
        int $stage,
        int $lesson,
        int $minutes,
    ): void {
        $base = rtrim((string) config('twa.base_url'), '/');
        $url = "{$base}/twa/exam/{$session->id}";

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: "🏁 <b>Экзамен запущен!</b>\nStage {$stage} · Lesson {$lesson} · <b>{$minutes} мин</b>\nНажмите кнопку, чтобы присоединиться.",
            buttonText: '🏁 Открыть экзамен',
            url: $url,
            parseMode: 'HTML',
        );
    }
}
