<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Domain\Telegram\Services\TelegramApi;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Handles `/start_training [stage] [lesson]` sent by a teacher in a group.
 *
 * Flow:
 *   1. Reject if not in a group or the group is not `active`.
 *   2. Resolve the teacher by `from.id`; reject unless they teach this group.
 *   3. Parse `stage` and `lesson` (defaults: 1 1).
 *   4. Delegate to TrainingSessionService::open() (idempotent).
 *   5. Post a group message with a WebApp inline button pointing to the TWA SPA.
 */
final class StartTrainingHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramApi $api,
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
        $text     = (string) ($message['text'] ?? '');

        return in_array($chatType, ['group', 'supergroup'], true)
            && preg_match('~^/start_training(?:@\w+)?(\s|$)~', $text) === 1;
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        $chatId  = (int) $message['chat']['id'];
        $fromId  = (int) ($message['from']['id'] ?? 0);
        $text    = (string) ($message['text'] ?? '');

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
            $this->api->sendMessage($chatId, 'Ваш Telegram-аккаунт не привязан к учителю. Попросите админа добавить вас.');

            return;
        }

        [$stageNumber, $lessonNumber] = $this->parseArgs($text);

        try {
            $session = $this->trainingService->open($group, $stageNumber, $lessonNumber, $teacher);
        } catch (TrainingSessionException $e) {
            $this->api->sendMessage($chatId, $this->humanizeError($e));

            return;
        }

        $this->postWebAppButton($chatId, $session, $stageNumber, $lessonNumber);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseArgs(string $text): array
    {
        if (preg_match('~^/start_training(?:@\w+)?\s+(\d+)\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        if (preg_match('~^/start_training(?:@\w+)?\s+(\d+)~', $text, $m) === 1) {
            return [(int) $m[1], 1];
        }

        return [1, 1];
    }

    private function humanizeError(TrainingSessionException $e): string
    {
        return match ($e->reason) {
            TrainingSessionException::REASON_NOT_TEACHER     => 'Только учитель этой группы может запускать тренировку.',
            TrainingSessionException::REASON_GROUP_INACTIVE  => 'Группа не активирована. Попросите админа активировать её в /admin.',
            TrainingSessionException::REASON_STAGE_NOT_FOUND => 'Указанный stage не найден.',
            TrainingSessionException::REASON_LESSON_NOT_FOUND => 'Урок не найден.',
            default => 'Не удалось запустить тренировку.',
        };
    }

    private function postWebAppButton(int $chatId, TrainingSession $session, int $stage, int $lesson): void
    {
        $base = rtrim((string) config('twa.base_url'), '/');
        $url  = "{$base}/twa/training/{$session->id}";

        $this->api->sendMessage(
            $chatId,
            "📚 Тренировка Stage {$stage} / Lesson {$lesson} запущена! Нажмите кнопку, чтобы начать.",
            replyMarkup: [
                'inline_keyboard' => [[
                    [
                        'text'    => '🎯 Открыть тренировку',
                        'web_app' => ['url' => $url],
                    ],
                ]],
            ],
        );
    }
}
