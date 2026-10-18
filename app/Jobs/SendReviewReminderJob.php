<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Learning\Services\ReviewReminderLinkBuilder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class SendReviewReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $learnerId,
        public readonly int $dueCount,
    ) {}

    public function handle(
        TelegramClient $telegram,
        ReviewReminderLinkBuilder $links,
        ProductEventRecorder $events,
        LocaleResolver $locales,
        GroupMembershipService $memberships,
    ): void {
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($this->learnerId);

        if ($learner === null || $learner->blocked_at !== null || $memberships->activeGroupIds($learner) === []) {
            return;
        }

        Cache::lock("telegram:review-reminder:{$learner->id}", 120)->block(10, function () use (
            $telegram,
            $links,
            $events,
            $locales,
            $learner,
        ): void {
            $recentlySent = NotificationLog::query()
                ->where('learner_id', $learner->id)
                ->where('type', 'review_reminder')
                ->where('sent_at', '>=', now()->subHours(24))
                ->exists();

            if ($recentlySent) {
                return;
            }

            $url = $links->buildTrainingUrl($learner);
            $locale = $locales->forLearner($learner);

            try {
                $telegram->sendWebAppButton(
                    chatId: $learner->telegram_user_id,
                    text: trans_choice('reminders.review', $this->dueCount, [], $locale),
                    buttonText: __('reminders.open_button', [], $locale),
                    url: $url,
                );
            } catch (TelegramForbiddenException $e) {
                // Bot blocked / dialog never started: retrying can never succeed,
                // so finish the job instead of failing it every day.
                Log::warning('telegram.review_reminder_forbidden', [
                    'learner_id' => $learner->id,
                    'reason' => $e->reason,
                ]);
                $events->record(
                    ProductEventName::ReminderFailed,
                    ['reason' => $e->reason, 'due_count' => $this->dueCount],
                    learner: $learner,
                );

                return;
            }

            NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => 'review_reminder',
                'payload' => [
                    'due_count' => $this->dueCount,
                ],
                'sent_at' => now(),
            ]);

            $events->record(
                ProductEventName::ReminderSent,
                ['due_count' => $this->dueCount],
                learner: $learner,
            );
        });
    }
}
