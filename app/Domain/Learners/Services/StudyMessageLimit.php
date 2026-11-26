<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\Enums\ReminderKind;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The common limit "one message about studies a day" (docs/12 §7.5, docs/31 §5б): the personal reminder ({@see ReminderSender})
 * and the reminder after a lesson ({@see LessonNudgeSender}) share it. A sender takes {@see self::lock()} and, under it, asks
 * {@see reached()} and writes its `notifications_log` row BEFORE the Telegram call - so of two senders racing for the same learner
 * exactly one sends.
 *
 * Reached = a row of any of {@see types()} since the earlier of the learner's local midnight and `now − min_interval_hours` (a floor
 * that also covers midnight and a changed reminder time).
 */
final class StudyMessageLimit
{
    public function __construct(private readonly LearnerClock $clock) {}

    /**
     * Every `notifications_log.type` that counts as "a message about studies went out".
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [...ReminderKind::notificationTypes(), LessonNudgeSender::NOTIFICATION_TYPE];
    }

    /** The per-learner lock of the senders of the limit (non-evictable store, docs/19 §3 п. 10). */
    public function lock(int $learnerId): Lock
    {
        return Cache::lock("reminders:send:{$learnerId}", 120);
    }

    public function reached(Learner $learner, CarbonImmutable $now): bool
    {
        // Either one is enough: a message already today (the learner's local calendar day, not the quota window), or one inside the
        // floor - i.e. since the earlier of the two.
        $dayStart = $this->clock->dayBoundsUtc($learner, $now)->startUtc;
        $floor = $now->subHours($this->minIntervalHours());
        $since = $dayStart->isBefore($floor) ? $dayStart : $floor;

        return NotificationLog::query()
            ->where('learner_id', $learner->id)
            ->whereIn('type', self::types())
            ->where('sent_at', '>=', $since)
            ->exists();
    }

    private function minIntervalHours(): int
    {
        return max(1, (int) config('learning.reminders.min_interval_hours', 2));
    }
}
