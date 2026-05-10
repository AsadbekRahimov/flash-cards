<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers;

use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamSessionService;
use App\Domain\Learning\Services\LeaderboardBuilder;
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

        /** @var User|null $teacher */
        $teacher = User::query()->where('telegram_user_id', $fromId)->first();
        if ($teacher === null) {
            $this->telegram->sendMessage($chatId, 'Ваш Telegram-аккаунт не привязан к учителю.');

            return;
        }

        $teaches = DB::table('teacher_groups')
            ->where('user_id', $teacher->id)
            ->where('telegram_group_id', $group->id)
            ->exists();

        if (! $teaches) {
            $this->telegram->sendMessage($chatId, 'Только учитель этой группы может закрыть экзамен.');

            return;
        }

        try {
            $session = $this->exams->closeOpenForGroup($group);
        } catch (ExamSessionException $e) {
            $this->telegram->sendMessage(
                $chatId,
                $e->reason === ExamSessionException::REASON_NO_OPEN_EXAM
                    ? 'Сейчас в группе нет открытого экзамена.'
                    : 'Не удалось закрыть экзамен.',
            );

            return;
        }

        $this->leaderboard->build($session);

        PostLeaderboardJob::dispatch($session->id)
            ->onQueue((string) config('telegram.queue', 'high'));
    }
}
