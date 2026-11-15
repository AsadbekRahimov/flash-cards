<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\GroupUpdateHandler;
use App\Domain\Telegram\Services\BotSupportContact;
use App\Domain\Telegram\Services\GroupSessionAnnouncer;
use App\Domain\Telegram\Services\LessonPicker;
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
final class StartTrainingHandler implements GroupUpdateHandler
{
    /** Callback data prefix shared with StartTrainingCallbackHandler. */
    public const CALLBACK_PREFIX = 'tstart:';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TrainingSessionService $trainingService,
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
        private readonly GroupSessionAnnouncer $announcer,
        private readonly LessonPicker $picker,
        private readonly TeacherAuthority $authority,
        private readonly BotSupportContact $support,
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
    public function handle(array $update, ?TelegramGroup $group = null): void
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
        $group ??= TelegramGroup::query()->where('chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        $locale = $this->locales->forGroup($group);

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, __('errors.teacher_not_linked_ask_admin', [], $locale));

            return;
        }

        $args = $this->parseArgs($text);

        if ($args === null) {
            // The picker is a menu of actions: show it to the group's teachers only.
            if (! $this->authority->canTeach($teacher, $group)) {
                $this->telegram->sendMessage($chatId, __('errors.training.not_teacher', [], $locale));

                return;
            }

            $this->picker->send($chatId, $group, $locale, self::CALLBACK_PREFIX, 'training.picker.prompt');

            return;
        }

        [$stageNumber, $lessonNumber] = $args;

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
                'source' => 'command',
                'reused' => ! $session->wasRecentlyCreated,
            ],
            group: $group,
            user: $teacher,
        );

        $this->postWebAppButton($chatId, $session, $stageNumber, $lessonNumber, $locale);
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

    private function humanizeError(TrainingSessionException $e, string $locale): string
    {
        return match ($e->reason) {
            TrainingSessionException::REASON_NOT_TEACHER => __('errors.training.not_teacher', [], $locale),
            TrainingSessionException::REASON_GROUP_INACTIVE => $this->support->groupInactive($locale),
            TrainingSessionException::REASON_STAGE_NOT_FOUND => __('errors.stage_not_found', [], $locale),
            TrainingSessionException::REASON_LESSON_NOT_FOUND => __('errors.lesson_not_found', [], $locale),
            default => __('errors.training.failed', [], $locale),
        };
    }

    private function postWebAppButton(int $chatId, TrainingSession $session, int $stage, int $lesson, string $locale): void
    {
        $base = rtrim((string) config('twa.base_url'), '/');

        $this->announcer->announce(
            chatId: $chatId,
            text: __('training.started', ['stage' => $stage, 'lesson' => $lesson], $locale),
            buttonText: __('training.open_button', [], $locale),
            url: "{$base}/twa/training/{$session->id}",
            failureText: __('errors.training.not_delivered', [], $locale),
            // A reused session was already announced earlier: only a freshly created one is withdrawn.
            cancel: function () use ($session): void {
                if ($session->wasRecentlyCreated) {
                    $this->trainingService->cancelUndelivered($session);
                }
            },
        );
    }
}
