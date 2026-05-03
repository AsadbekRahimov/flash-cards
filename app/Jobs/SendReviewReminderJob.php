<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Telegram\Services\TelegramApi;
use App\Models\NotificationLog;
use App\Models\Student;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendReviewReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $studentId,
        public readonly int $dueCount,
    ) {}

    public function handle(TelegramApi $api, ReviewReminderLinkBuilder $links): void
    {
        /** @var Student|null $student */
        $student = Student::query()
            ->with('group')
            ->find($this->studentId);

        if ($student === null || ! $student->is_active || $student->group?->status !== 'active') {
            return;
        }

        $recentlySent = NotificationLog::query()
            ->where('student_id', $student->id)
            ->where('type', 'review_reminder')
            ->where('sent_at', '>=', now()->subHours(24))
            ->exists();

        if ($recentlySent) {
            return;
        }

        $url = $links->buildTrainingUrl($student);

        $api->sendMessage(
            $student->telegram_user_id,
            "Пора повторить {$this->dueCount} слов в LexiFlow.",
            replyMarkup: [
                'inline_keyboard' => [[
                    [
                        'text' => 'Открыть LexiFlow',
                        'web_app' => ['url' => $url],
                    ],
                ]],
            ],
        );

        NotificationLog::query()->create([
            'student_id' => $student->id,
            'type' => 'review_reminder',
            'payload' => [
                'due_count' => $this->dueCount,
                'telegram_group_id' => $student->telegram_group_id,
            ],
            'sent_at' => now(),
        ]);
    }
}
