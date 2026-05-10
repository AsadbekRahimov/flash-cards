<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Telegram\Contracts\TelegramClient;
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

    public function handle(TelegramClient $telegram, ReviewReminderLinkBuilder $links): void
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

        $telegram->sendWebAppButton(
            chatId: $student->telegram_user_id,
            text: "Пора повторить {$this->dueCount} слов в LexiFlow.",
            buttonText: 'Открыть LexiFlow',
            url: $url,
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
