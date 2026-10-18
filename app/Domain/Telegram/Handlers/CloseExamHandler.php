<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Handlers\Contracts\UpdateHandler;
use App\Jobs\PostLeaderboardJob;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Handles `/close_exam` in a group — a teacher manually finalizes the
 * currently-open exam and triggers immediate leaderboard announcement.
 */
final class CloseExamHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ExamSessionService $exams,
        private readonly LeaderboardBuilder $leaderboard,
        private readonly ProductEventRecorder $events,
        private readonly LocaleResolver $locales,
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
            && preg_match('~^/close_exam(?:@\w+)?(\s|$)~', $text) === 1;
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

        $teaches = DB::table('teacher_groups')
            ->where('user_id', $teacher->id)
            ->where('telegram_group_id', $group->id)
            ->exists();

        if (! $teaches) {
            $this->telegram->sendMessage($chatId, __('errors.exam.close_not_teacher', [], $locale));

            return;
        }

        try {
            $session = $this->exams->closeOpenForGroup($group);
        } catch (ExamSessionException $e) {
            $this->telegram->sendMessage(
                $chatId,
                $e->reason === ExamSessionException::REASON_NO_OPEN_EXAM
                    ? __('errors.exam.no_open_exam', [], $locale)
                    : __('errors.exam.close_failed', [], $locale),
            );

            return;
        }

        $this->events->record(
            ProductEventName::ExamClosed,
            ['exam_session_id' => $session->id, 'source' => 'manual'],
            group: $group,
            user: $teacher,
        );

        $this->leaderboard->build($session);

        PostLeaderboardJob::dispatch($session->id)
            ->onQueue((string) config('telegram.queue', 'high'));
    }
}
