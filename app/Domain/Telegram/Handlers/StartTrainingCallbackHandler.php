<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Handles the callback_query emitted when a teacher taps a lesson button
 * from the picker posted by StartTrainingHandler.
 *
 * Callback data format: `tstart:{stage}:{lesson}`  (e.g. `tstart:2:4`)
 */
final class StartTrainingCallbackHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TrainingSessionService $trainingService,
    ) {}

    /** @param array<string, mixed> $update */
    public function matches(array $update): bool
    {
        $data = (string) ($update['callback_query']['data'] ?? '');

        return str_starts_with($data, StartTrainingHandler::CALLBACK_PREFIX);
    }

    public function requiresActiveGroup(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $cq */
        $cq = $update['callback_query'];
        $callbackQueryId = (string) ($cq['id'] ?? '');

        // Always answer immediately to dismiss the loading spinner.
        if ($callbackQueryId !== '') {
            $this->telegram->answerCallbackQuery($callbackQueryId);
        }

        $fromId = (int) ($cq['from']['id'] ?? 0);
        $chatId = (int) ($cq['message']['chat']['id'] ?? 0);

        if ($fromId === 0 || $chatId === 0) {
            return;
        }

        $data = (string) ($cq['data'] ?? '');
        $payload = substr($data, strlen(StartTrainingHandler::CALLBACK_PREFIX));
        $parts = explode(':', $payload);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return;
        }

        $stageNumber = (int) $parts[0];
        $lessonNumber = (int) $parts[1];

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

        try {
            $session = $this->trainingService->open($group, $stageNumber, $lessonNumber, $teacher);
        } catch (TrainingSessionException $e) {
            $this->telegram->sendMessage($chatId, $this->humanizeError($e));

            return;
        }

        $this->postWebAppButton($chatId, $session, $stageNumber, $lessonNumber);
    }

    private function humanizeError(TrainingSessionException $e): string
    {
        return match ($e->reason) {
            TrainingSessionException::REASON_NOT_TEACHER => 'Только учитель этой группы может запускать тренировку.',
            TrainingSessionException::REASON_GROUP_INACTIVE => 'Группа не активирована.',
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
