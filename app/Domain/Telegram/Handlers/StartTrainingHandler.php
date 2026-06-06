<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Handles `/start_training [stage] [lesson]` sent by a teacher in a group.
 *
 * Flow:
 *   1. Reject if not in a group or the group is not `active`.
 *   2. Resolve the teacher by `from.id`; reject unless they teach this group.
 *   3a. If stage + lesson params given: open session and post WebApp button.
 *   3b. If no params: show an inline keyboard listing available lessons
 *       (up to 20). The callback is handled by StartTrainingCallbackHandler.
 *   4. Delegate to TrainingSessionService::open() (idempotent).
 *   5. Post a group message with a WebApp inline button pointing to the TWA SPA.
 */
final class StartTrainingHandler implements UpdateHandler
{
    /** Max lessons shown in the picker keyboard. */
    private const MAX_PICKER_ROWS = 20;

    /** Callback data prefix shared with StartTrainingCallbackHandler. */
    public const CALLBACK_PREFIX = 'tstart:';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TrainingSessionService $trainingService,
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
            && preg_match('~^/start_training(?:@\w+)?(\s|$)~', $text) === 1;
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

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, 'Ваш Telegram-аккаунт не привязан к учителю. Попросите админа добавить вас.');

            return;
        }

        $args = $this->parseArgs($text);

        if ($args === null) {
            $this->sendLessonPicker($chatId);

            return;
        }

        [$stageNumber, $lessonNumber] = $args;

        try {
            $session = $this->trainingService->open($group, $stageNumber, $lessonNumber, $teacher);
        } catch (TrainingSessionException $e) {
            $this->telegram->sendMessage($chatId, $this->humanizeError($e));

            return;
        }

        $this->postWebAppButton($chatId, $session, $stageNumber, $lessonNumber);
    }

    /**
     * @return array{0:int,1:int}|null null when no arguments were provided
     */
    private function parseArgs(string $text): ?array
    {
        if (preg_match('~^/start_training(?:@\w+)?\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('~^/start_training(?:@\w+)?\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], 1];
        }

        return null;
    }

    private function sendLessonPicker(int $chatId): void
    {
        $lessons = Lesson::query()
            ->with('stage')
            ->orderBy('stage_id')
            ->orderBy('number')
            ->limit(self::MAX_PICKER_ROWS)
            ->get();

        if ($lessons->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'В системе пока нет уроков. Попросите администратора загрузить контент.');

            return;
        }

        $rows = $lessons->map(function (Lesson $lesson): array {
            $stageNum = $lesson->stage->number ?? '?';
            $lessonNum = $lesson->number;

            return [[
                'text' => "Stage {$stageNum} / Lesson {$lessonNum}",
                'callback_data' => self::CALLBACK_PREFIX."{$stageNum}:{$lessonNum}",
            ]];
        })->values()->all();

        $this->telegram->sendMessage(
            chatId: $chatId,
            text: 'Выберите урок для тренировки:',
            replyMarkup: ['inline_keyboard' => $rows],
        );
    }

    private function humanizeError(TrainingSessionException $e): string
    {
        return match ($e->reason) {
            TrainingSessionException::REASON_NOT_TEACHER => 'Только учитель этой группы может запускать тренировку.',
            TrainingSessionException::REASON_GROUP_INACTIVE => 'Группа не активирована. Попросите админа активировать её в /admin.',
            TrainingSessionException::REASON_STAGE_NOT_FOUND => 'Указанный stage не найден.',
            TrainingSessionException::REASON_LESSON_NOT_FOUND => 'Урок не найден.',
            default => 'Не удалось запустить тренировку.',
        };
    }

    private function postWebAppButton(int $chatId, TrainingSession $session, int $stage, int $lesson): void
    {
        $base = rtrim((string) config('twa.base_url'), '/');
        $url = "{$base}/twa/training/{$session->id}";

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: "📚 Тренировка Stage {$stage} / Lesson {$lesson} запущена! Нажмите кнопку, чтобы начать.",
            buttonText: '🎯 Открыть тренировку',
            url: $url,
        );
    }
}
