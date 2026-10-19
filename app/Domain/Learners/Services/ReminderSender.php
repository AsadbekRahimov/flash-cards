<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends one claimed personal reminder (docs/12 §7.5) — the body of
 * {@see SendLearnerReminderJob}, kept here so it can be exercised directly.
 *
 * Order of checks, each cheap and each able to end the job quietly:
 *  1. the learner still exists, is not banned, DM not blocked, reminders still on
 *     (a `/settings` change between claim and send wins);
 *  2. the claim is fresh (`max_delay_minutes`): a reminder hours late is spam;
 *  3. under a per-learner cache lock, `notifications_log` says no reminder of any kind
 *     went out on the learner's local day, nor within the last `min_interval_hours`
 *     (a floor that also covers midnight and a changed reminder time) — this is what
 *     makes a retried or duplicated job harmless (the dispatcher's claim is the first
 *     level);
 *  4. {@see ReminderContentResolver} picks review / streak_at_risk / nothing.
 *
 * At most once: the `notifications_log` row is written BEFORE the Telegram call, inside
 * the same lock. A timeout or 5xx may mean "delivered anyway", so on such an error the
 * row stays, the exception propagates, and the queue's retry meets the row and ends in
 * {@see ReminderOutcome::SkippedRecent} — a missed reminder is better than a double one.
 *
 * Telegram 403 (`bot_blocked`, `not_started`, `user_deactivated`) or 400 `chat not found`
 * means certainly not delivered: the row is deleted again and `dm_blocked_at` is set —
 * the learner leaves the dispatcher's selection until they write to the bot (any DM
 * clears the flag; so does a `my_chat_member` `member` update in the private chat).
 *
 * No database transaction is opened here on purpose: `StreakService::current()` may
 * repair the `learners` projection with its own UPDATE (lock order, docs/12 §3.1).
 */
final class ReminderSender
{
    public const TWA_PATH = '/twa/?from=reminder';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly ReminderContentResolver $contents,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
        private readonly LearnerClock $clock,
    ) {}

    public function send(int $learnerId, CarbonImmutable $scheduledFor, ?CarbonImmutable $now = null): ReminderOutcome
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);

        if ($learner === null
            || $learner->blocked_at !== null
            || $learner->dm_blocked_at !== null
            || $learner->reminder_time === null
            || $learner->reminder_time === ''
        ) {
            return ReminderOutcome::SkippedUnreachable;
        }

        if ($scheduledFor->utc()->addMinutes($this->maxDelayMinutes())->isBefore($now)) {
            return ReminderOutcome::SkippedStale;
        }

        return Cache::lock("reminders:send:{$learner->id}", 120)->block(10, function () use ($learner, $scheduledFor, $now): ReminderOutcome {
            if ($this->recentlyReminded($learner, $now)) {
                return ReminderOutcome::SkippedRecent;
            }

            $content = $this->contents->resolve($learner, $now);
            if ($content === null) {
                return ReminderOutcome::SkippedNothingToSay;
            }

            $locale = $this->locales->forLearner($learner);

            // Before the call, not after: see the class comment.
            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => $content->kind->notificationType(),
                'payload' => [
                    'kind' => $content->kind->value,
                    'due_count' => $content->dueCount,
                    'streak' => $content->streakCurrent,
                    'scheduled_for' => $scheduledFor->utc()->toIso8601String(),
                ],
                'sent_at' => $now,
            ]);

            try {
                $this->telegram->sendWebAppButton(
                    chatId: $learner->telegram_user_id,
                    text: $this->text($content, $locale),
                    buttonText: __('reminders.open_button', [], $locale),
                    url: rtrim((string) config('twa.base_url'), '/').self::TWA_PATH,
                );
            } catch (TelegramForbiddenException $e) {
                // Definitely not delivered: it must not count against the day.
                $log->delete();
                $this->markDmBlocked($learner, $now);

                Log::warning('telegram.reminder_forbidden', [
                    'learner_id' => $learner->id,
                    'reason' => $e->reason,
                ]);
                $this->events->record(
                    ProductEventName::ReminderFailed,
                    ['reason' => $e->reason, 'kind' => $content->kind->value],
                    learner: $learner,
                );

                return ReminderOutcome::Forbidden;
            }

            $this->events->record(
                ProductEventName::ReminderSent,
                ['kind' => $content->kind->value, 'due_count' => $content->dueCount],
                learner: $learner,
            );

            return ReminderOutcome::Sent;
        });
    }

    private function text(ReminderContent $content, string $locale): string
    {
        return match ($content->kind) {
            ReminderKind::Review => trans_choice('reminders.review', $content->dueCount, [], $locale),
            ReminderKind::StreakAtRisk => trans_choice('reminders.streak_at_risk', $content->streakCurrent, [], $locale),
        };
    }

    private function recentlyReminded(Learner $learner, CarbonImmutable $now): bool
    {
        // Either one is enough: a reminder already today (the learner's local calendar day,
        // not the quota window), or one inside the floor — i.e. since the earlier of the two.
        $dayStart = $this->clock->dayBoundsUtc($learner, $now)->startUtc;
        $floor = $now->subHours($this->minIntervalHours());
        $since = $dayStart->isBefore($floor) ? $dayStart : $floor;

        return NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->whereIn('type', ReminderKind::notificationTypes())
            ->where('sent_at', '>=', $since)
            ->exists();
    }

    /**
     * Single-column UPDATE, no lock: an inbound DM clears the flag with its own conditional
     * UPDATE, and the millisecond race between a 403 and a DM is harmless in both orders
     * (worst case one more 403 tomorrow, or silence until the learner writes again).
     */
    private function markDmBlocked(Learner $learner, CarbonImmutable $now): void
    {
        Learner::query()
            ->whereKey($learner->id)
            ->update(['dm_blocked_at' => $now->format('Y-m-d H:i:s')]);
    }

    private function maxDelayMinutes(): int
    {
        return max(15, (int) config('learning.reminders.max_delay_minutes', 120));
    }

    private function minIntervalHours(): int
    {
        return max(1, (int) config('learning.reminders.min_interval_hours', 2));
    }
}
