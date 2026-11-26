<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Learners\DTO\DayPlan;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\BotButtons;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Support\TwaScreen;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Jobs\SendLessonNudgeJob;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The bot's reminder after a lesson (docs/31 §5б, G1-G2) - the body of {@see SendLessonNudgeJob}: "the lesson is done, fix its N new
 * words in a game" with one button that opens the game of the lesson (`/practice/{course}?lesson=<id>&play=pairs_timed`).
 *
 * In the day of the lesson the text speaks of fixing the words, never of points: a game gives points only for a word due for review
 * (docs/28), and the words learned today are not.
 *
 * Rules of {@see send()}, re-checked when the job runs, in this order (each ends it quietly):
 *  1. switched on (`learning.reminders.lesson_nudge.enabled`);
 *  2. the learner is there, not banned, the DM is open (`dm_blocked_at` and `dm_blocked_reason` empty) and the reminders are on
 *     (`reminder_time` set - "off" in `/settings` is NULL there) - {@see reachable()};
 *  3. it is still the local day the job was queued for, and not the night of the learner (a local hour in
 *     [`quiet_from_hour`, `quiet_to_hour`)): a night job is dropped, not moved to the morning;
 *  4. {@see DayPlan::offersGame()}: a lesson with new words was played to its end today, the game is open to the learner and no game
 *     on its words was played since;
 *  5. under the lock of {@see StudyMessageLimit} (the one of the personal reminders): no message about studies (a reminder or this
 *     one) went out in the learner's local day - that is also "at most one of these a day"; then the `notifications_log` row
 *     (`type = lesson_nudge`) is written BEFORE the send.
 *
 * Errors (docs/34 §4в п. 8): a 403 removes the row and marks the DM unreachable ({@see DmReachability::markUnreachable()}); any other
 * definite refusal (a 429 included) removes it and is rethrown - {@see ThrottleTelegramOutbound} puts the job back for `retry_after`
 * on a 429; a timeout / 5xx keeps it (better missed than sent twice).
 */
final class LessonNudgeSender
{
    public const NOTIFICATION_TYPE = 'lesson_nudge';

    /** `from=` of the button into the Mini App. */
    public const FROM = 'lesson_nudge';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly LocaleResolver $locales,
        private readonly DmReachability $reachability,
        private readonly DayPlanService $plans,
        private readonly StudyMessageLimit $limit,
        private readonly LearnerClock $clock,
        private readonly DeckTitles $titles,
        private readonly BotButtons $buttons,
        private readonly ProductEventRecorder $events,
    ) {}

    /** Not banned, the DM open and the reminders on: the learner the reminder may go to at all. */
    public static function reachable(Learner $learner): bool
    {
        return $learner->blocked_at === null
            && $learner->dm_blocked_at === null
            && ($learner->dm_blocked_reason === null || $learner->dm_blocked_reason === '')
            && $learner->reminder_time !== null
            && $learner->reminder_time !== '';
    }

    /**
     * True when a message went out.
     *
     * @param  string  $localDate  the local day (`Y-m-d`) the job was queued for
     */
    public function send(int $learnerId, string $localDate, ?CarbonImmutable $now = null): bool
    {
        if (! LessonNudgeScheduler::enabled()) {
            return false;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();

        // A soft-deleted learner is not found.
        /** @var Learner|null $learner */
        $learner = Learner::query()->find($learnerId);

        if ($learner === null || ! self::reachable($learner)) {
            return false;
        }

        if ($this->clock->dayBoundsUtc($learner, $now)->date !== $localDate || $this->isNight($learner, $now)) {
            return false;
        }

        $plan = $this->plans->forLearner($learner, $now, withCourse: false);
        $lessonId = $plan->todayLessonId;

        if (! $plan->offersGame() || $lessonId === null || $plan->todayWords < 1) {
            return false;
        }

        /** @var Lesson|null $lesson */
        $lesson = Lesson::query()->with('stage.course')->find($lessonId);
        $course = $lesson?->stage?->course;

        if ($lesson === null || $course === null) {
            return false;
        }

        $locale = $this->locales->forLearner($learner);
        $title = $this->titles->forLesson($lesson, $learner);
        $text = $title === null
            ? trans_choice('reminders.lesson_nudge.text_untitled', $plan->todayWords, [], $locale)
            : trans_choice('reminders.lesson_nudge.text', $plan->todayWords, ['lesson' => $title], $locale);
        $markup = $this->buttons->single($learner->telegram_user_id, $this->buttons->open(
            (string) __('buttons.lesson_game', [], $locale),
            TwaScreen::PracticeHub,
            ['slug' => $course->slug],
            ['lesson' => $lessonId, 'play' => DayPlan::GAME_MODE, 'from' => self::FROM],
        ));

        $sent = $this->limit->lock($learner->id)->block(10, function () use ($learner, $lessonId, $plan, $localDate, $text, $markup, $now): bool {
            if ($this->limit->reached($learner, $now)) {
                return false;
            }

            // Before the call, not after: see the class comment.
            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => self::NOTIFICATION_TYPE,
                'payload' => ['lesson_id' => $lessonId, 'words' => $plan->todayWords, 'date' => $localDate],
                'sent_at' => $now,
            ]);

            try {
                $this->telegram->sendMessage($learner->telegram_user_id, $text, replyMarkup: $markup);
            } catch (TelegramForbiddenException $e) {
                // Certainly not delivered: it must not count against the day, and the learner leaves the DM selections.
                $log->delete();
                $this->reachability->markUnreachable($learner->telegram_user_id, $e->reason, $now);

                Log::warning('telegram.lesson_nudge_forbidden', ['learner_id' => $learner->id, 'reason' => $e->reason]);

                return false;
            } catch (TelegramApiException $e) {
                if ($e->isApiResponse()) {
                    // Definitely not delivered (a 429 or another refusal): the retry must find no row and send.
                    $log->delete();
                }

                // A timeout / 5xx may have been delivered: the row stays (docs/34 §4в п. 8).
                throw $e;
            }

            return true;
        });

        if ($sent) {
            $this->events->record(ProductEventName::LessonNudgeSent, [
                'lesson_id' => $lessonId,
                'words' => $plan->todayWords,
            ], learner: $learner);
        }

        return $sent;
    }

    /** A local hour in [quiet_from_hour, quiet_to_hour); the interval may wrap midnight (22 -> 8). */
    private function isNight(Learner $learner, CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone($this->clock->timezone($learner))->format('G');
        $from = (int) config('learning.reminders.lesson_nudge.quiet_from_hour', 22);
        $to = (int) config('learning.reminders.lesson_nudge.quiet_to_hour', 8);

        if ($from === $to) {
            return false;
        }

        return $from < $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }
}
