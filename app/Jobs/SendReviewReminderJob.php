<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\NotificationLog;
use App\Models\Student;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class SendReviewReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $studentId,
        public readonly int $dueCount,
    ) {}

    public function handle(
        TelegramClient $telegram,
        ReviewReminderLinkBuilder $links,
        ProductEventRecorder $events,
        LocaleResolver $locales,
    ): void {
        /** @var Student|null $student */
        $student = Student::query()
            ->with('group')
            ->find($this->studentId);

        if ($student === null || ! $student->is_active || $student->group?->status !== 'active') {
            return;
        }

        Cache::lock("telegram:review-reminder:{$student->id}", 120)->block(10, function () use (
            $telegram,
            $links,
            $events,
            $locales,
            $student,
        ): void {
            $recentlySent = NotificationLog::query()
                ->where('student_id', $student->id)
                ->where('type', 'review_reminder')
                ->where('sent_at', '>=', now()->subHours(24))
                ->exists();

            if ($recentlySent) {
                return;
            }

            $url = $links->buildTrainingUrl($student);
            $locale = $locales->forStudent($student);

            try {
                $telegram->sendWebAppButton(
                    chatId: $student->telegram_user_id,
                    text: trans_choice('reminders.review', $this->dueCount, [], $locale),
                    buttonText: __('reminders.open_button', [], $locale),
                    url: $url,
                );
            } catch (TelegramForbiddenException $e) {
                // Bot blocked / dialog never started: retrying can never succeed,
                // so finish the job instead of failing it every day.
                Log::warning('telegram.review_reminder_forbidden', [
                    'student_id' => $student->id,
                    'reason' => $e->reason,
                ]);
                $events->record(
                    ProductEventName::ReminderFailed,
                    ['reason' => $e->reason, 'due_count' => $this->dueCount],
                    student: $student,
                );

                return;
            }

            NotificationLog::query()->create([
                'student_id' => $student->id,
                'type' => 'review_reminder',
                'payload' => [
                    'due_count' => $this->dueCount,
                    'telegram_group_id' => $student->telegram_group_id,
                ],
                'sent_at' => now(),
            ]);

            $events->record(
                ProductEventName::ReminderSent,
                ['due_count' => $this->dueCount],
                student: $student,
            );
        });
    }
}
