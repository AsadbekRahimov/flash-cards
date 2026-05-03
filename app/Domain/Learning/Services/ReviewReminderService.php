<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Jobs\SendReviewReminderJob;
use App\Models\Student;

final class ReviewReminderService
{
    public const DEFAULT_THRESHOLD = 5;

    public function queueDueReminders(int $threshold = self::DEFAULT_THRESHOLD): int
    {
        $queued = 0;

        Student::query()
            ->where('is_active', true)
            ->whereHas('group', fn ($q) => $q->where('status', 'active'))
            ->whereHas(
                'repetitions',
                fn ($q) => $q->where('next_review_at', '<=', now()),
                '>=',
                $threshold,
            )
            ->withCount([
                'repetitions as due_repetitions_count' => fn ($q) => $q->where('next_review_at', '<=', now()),
            ])
            ->whereDoesntHave('notifications', function ($q): void {
                $q->where('type', 'review_reminder')
                    ->where('sent_at', '>=', now()->subHours(24));
            })
            ->chunkById(200, function ($students) use (&$queued): void {
                foreach ($students as $student) {
                    $dueCount = (int) ($student->due_repetitions_count ?? 0);

                    SendReviewReminderJob::dispatch($student->id, $dueCount)
                        ->onQueue((string) config('telegram.queue', 'high'));

                    $queued++;
                }
            });

        return $queued;
    }
}
