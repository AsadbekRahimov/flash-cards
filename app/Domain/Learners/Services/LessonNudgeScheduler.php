<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learning\Events\LessonWithNewWordsFinished;
use App\Jobs\SendLessonNudgeJob;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Queues the bot's reminder after a lesson (docs/31 §5б, G1) when a lesson with new words is finished
 * ({@see LessonWithNewWordsFinished}): one {@see SendLessonNudgeJob} `learning.reminders.lesson_nudge.delay_minutes` later, at
 * most once a local day of the learner. The job ({@see LessonNudgeSender}) checks everything again when it runs.
 *
 * "Once a day" is the marker {@see markerKey()} in the non-evictable Redis (docs/19 §3: version in the key, a TTL up to the end of
 * the local day plus {@see MARKER_MARGIN_SECONDS}): `Cache::add` - of two `/finish` racing exactly one queues. The marker is not
 * removed when the job later decides not to send: the second lesson of the day does not queue another one.
 *
 * Nothing is queued (and no marker set) for a learner the job would skip anyway: switched off, banned, the DM closed or the
 * reminders off. No statement of its own: the learner is the row `/finish` already has.
 */
final class LessonNudgeScheduler
{
    /** How long the marker outlives the local day: a job of 23:50 + 45 min still finds it while it runs. */
    public const MARKER_MARGIN_SECONDS = 6 * 3600;

    public function __construct(private readonly LearnerClock $clock) {}

    public static function enabled(): bool
    {
        return (bool) config('learning.reminders.lesson_nudge.enabled', true);
    }

    /** `learners:lesson-nudge:v1:{learner id}:{local date}` - set when the reminder of that day was queued. */
    public static function markerKey(int $learnerId, string $localDate): string
    {
        return "learners:lesson-nudge:v1:{$learnerId}:{$localDate}";
    }

    /** True when a job was queued now. */
    public function schedule(Learner $learner, ?CarbonImmutable $now = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();

        if (! LessonNudgeSender::reachable($learner)) {
            return false;
        }

        $day = $this->clock->dayBoundsUtc($learner, $now);
        $key = self::markerKey($learner->id, $day->date);
        $ttl = max(60, (int) $now->diffInSeconds($day->endUtc, absolute: true) + self::MARKER_MARGIN_SECONDS);

        if (! Cache::add($key, 1, $ttl)) {
            return false;
        }

        try {
            SendLessonNudgeJob::dispatch($learner->id, $day->date)
                ->onQueue((string) config('learning.reminders.lesson_nudge.queue', 'default'))
                ->delay($now->addMinutes(self::delayMinutes()));
        } catch (Throwable $e) {
            // Not queued: the next lesson of the day may try again.
            Cache::forget($key);

            throw $e;
        }

        return true;
    }

    public static function delayMinutes(): int
    {
        return max(1, (int) config('learning.reminders.lesson_nudge.delay_minutes', 45));
    }
}
