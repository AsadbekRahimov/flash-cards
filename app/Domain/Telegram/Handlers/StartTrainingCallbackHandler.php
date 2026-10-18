<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Localization\Services\LocaleResolver;
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
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
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

        $locale = $this->locales->forGroup($group);

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, __('errors.teacher_not_linked', [], $locale));

            return;
        }

        try {
            $session = $this->trainingService->open($group, $stageNumber, $lessonNumber, $teacher);
        } catch (TrainingSessionException $e) {
            $this->telegram->sendMessage($chatId, $this->humanizeError($e, $locale));

            return;
        }

        $this->events->record(
            ProductEventName::TrainingSessionOpened,
            [
                'training_session_id' => $session->id,
                'stage' => $stageNumber,
                'lesson' => $lessonNumber,
                'source' => 'picker',
                'reused' => ! $session->wasRecentlyCreated,
            ],
            group: $group,
            user: $teacher,
        );

        $this->postWebAppButton($chatId, $session, $stageNumber, $lessonNumber, $locale);
    }

    private function humanizeError(TrainingSessionException $e, string $locale): string
    {
        return match ($e->reason) {
            TrainingSessionException::REASON_NOT_TEACHER => __('errors.training.not_teacher', [], $locale),
            TrainingSessionException::REASON_GROUP_INACTIVE => __('errors.group_inactive', [], $locale),
            TrainingSessionException::REASON_STAGE_NOT_FOUND => __('errors.stage_not_found', [], $locale),
            TrainingSessionException::REASON_LESSON_NOT_FOUND => __('errors.lesson_not_found', [], $locale),
            default => __('errors.training.failed', [], $locale),
        };
    }

    private function postWebAppButton(int $chatId, TrainingSession $session, int $stage, int $lesson, string $locale): void
    {
        $base = rtrim((string) config('twa.base_url'), '/');
        $url = "{$base}/twa/training/{$session->id}";

        $this->telegram->sendWebAppButton(
            chatId: $chatId,
            text: __('training.started', ['stage' => $stage, 'lesson' => $lesson], $locale),
            buttonText: __('training.open_button', [], $locale),
            url: $url,
        );
    }
}
