<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\DTO\ReminderQuiz;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learners\Enums\ReminderOutcome;
use App\Domain\Learners\Enums\ReminderVariant;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\ReminderQuizMessenger;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *  4. {@see ReminderContentResolver} picks the kind of reminder (or nothing);
 *  5. {@see ReminderQuizBuilder} may turn it into a question with four answers (owner decision 2026-11-17): then the
 *     state of the question is stored BEFORE the Telegram call (a tap can only come after it) and the message has the
 *     answer buttons instead of the one app button. Whatever goes wrong while building or storing it, the reminder goes
 *     out as the plain text — it is never lost to the question.
 *     While the experiment of {@see ReminderExperiment} runs, a quiz-eligible reminder gets the learner's sticky arm first:
 *     the `plain` arm never builds a question; the `quiz` arm tries as always (intent to treat: a learner whose question
 *     could not be built stays in the quiz arm, with `quiz_built = false`). The arm goes to `reminder.sent` /
 *     `reminder.failed` and to the log row as `variant`, next to `experiment` (the configured salt, the name of the
 *     experiment); without the experiment none of them changes.
 *
 * At most once: the `notifications_log` row is written BEFORE the Telegram call, inside
 * the same lock. A timeout or 5xx may mean "delivered anyway", so on such an error the
 * row stays, the exception propagates, and the queue's retry meets the row and ends in
 * {@see ReminderOutcome::SkippedRecent} — a missed reminder is better than a double one.
 *
 * Telegram 403 (`bot_blocked`, `not_started`, `user_deactivated`) or 400 `chat not found`
 * means certainly not delivered: the row (and the stored question) is deleted again and `dm_blocked_at` is set —
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
        private readonly ReminderQuizBuilder $quizzes,
        private readonly ReminderQuizStore $quizStore,
        private readonly ReminderQuizMessenger $quizMessenger,
        private readonly ReminderExperiment $experiment,
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
            $variant = $this->experiment->variantFor($learner, $content, $now);
            $quiz = $variant === ReminderVariant::Plain ? null : $this->quizFor($learner, $content, $locale, $now);
            // The arm and the experiment (its salt) only while it runs: the props and the payload of the other reminders stay what they were.
            $arm = $variant === null ? [] : ['variant' => $variant->value, 'experiment' => ReminderExperiment::salt()];

            // Before the call, not after: see the class comment.
            $log = NotificationLog::query()->create([
                'learner_id' => $learner->id,
                'type' => $content->kind->notificationType(),
                'payload' => [
                    'kind' => $content->kind->value,
                    'due_count' => $content->dueCount,
                    'streak' => $content->streakCurrent,
                    'idle_days' => $content->idleDays,
                    'quiz' => $quiz === null ? null : ['word_id' => $quiz->wordId, 'exercise' => $quiz->exercise->value],
                    'scheduled_for' => $scheduledFor->utc()->toIso8601String(),
                    ...$arm,
                ],
                'sent_at' => $now,
            ]);

            try {
                if ($quiz !== null) {
                    $view = $this->quizMessenger->question($content, $quiz, $locale);
                    $this->telegram->sendMessage($learner->telegram_user_id, $view['text'], null, $view['reply_markup']);
                } else {
                    $this->telegram->sendWebAppButton(
                        chatId: $learner->telegram_user_id,
                        text: $this->text($content, $locale),
                        buttonText: __('reminders.open_button', [], $locale),
                        url: rtrim((string) config('twa.base_url'), '/').self::TWA_PATH,
                    );
                }
            } catch (TelegramForbiddenException $e) {
                // Definitely not delivered: it must not count against the day, and nobody can answer the question.
                $log->delete();
                if ($quiz !== null) {
                    $this->forgetQuiz($learner);
                }
                $this->markDmBlocked($learner, $now);

                Log::warning('telegram.reminder_forbidden', [
                    'learner_id' => $learner->id,
                    'reason' => $e->reason,
                ]);
                $this->events->record(
                    ProductEventName::ReminderFailed,
                    ['reason' => $e->reason, 'kind' => $content->kind->value, ...$arm],
                    learner: $learner,
                );

                return ReminderOutcome::Forbidden;
            }

            // `quiz` only on a question: the props of a plain reminder stay exactly what they were. `quiz_built` only in the quiz arm.
            $this->events->record(
                ProductEventName::ReminderSent,
                [
                    'kind' => $content->kind->value,
                    'due_count' => $content->dueCount,
                    ...($quiz === null ? [] : ['quiz' => true]),
                    ...$arm,
                    ...($variant === ReminderVariant::Quiz ? ['quiz_built' => $quiz !== null] : []),
                ],
                learner: $learner,
            );
            if ($quiz !== null) {
                $this->events->record(
                    ProductEventName::ReminderQuizSent,
                    ['kind' => $content->kind->value, 'word_id' => $quiz->wordId, 'exercise' => $quiz->exercise->value, 'due_count' => $content->dueCount],
                    learner: $learner,
                );
            }

            return ReminderOutcome::Sent;
        });
    }

    /**
     * The question of this reminder, already stored for the answer, or null for the plain text: nothing to ask, the
     * builder failed, or Redis refused the state (a question whose taps all say "outdated" is worse than none).
     */
    private function quizFor(Learner $learner, ReminderContent $content, string $locale, CarbonImmutable $now): ?ReminderQuiz
    {
        $quiz = $this->quizzes->tryBuild($learner, $content, $locale, $now);
        if ($quiz === null) {
            return null;
        }

        try {
            $this->quizStore->put($learner->id, $quiz, $content, $now);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return $quiz;
    }

    private function forgetQuiz(Learner $learner): void
    {
        try {
            $this->quizStore->forget($learner->id);
        } catch (Throwable $e) {
            // The key expires on its own; the 403 handling must go on.
            report($e);
        }
    }

    private function text(ReminderContent $content, string $locale): string
    {
        return match ($content->kind) {
            ReminderKind::Review => trans_choice('reminders.review', $content->dueCount, [], $locale),
            ReminderKind::StreakAtRisk => trans_choice('reminders.streak_at_risk', $content->streakCurrent, [], $locale),
            // The plural form follows the number of days; the due count is a separate, agreement-free placeholder.
            ReminderKind::Inactive => trans_choice(
                $content->dueCount > 0 ? 'reminders.inactive_due' : 'reminders.inactive',
                $content->idleDays,
                ['due' => $content->dueCount],
                $locale,
            ),
            ReminderKind::NeverStarted => __('reminders.never_started', [], $locale),
            ReminderKind::NewWords => __('reminders.new_words', [], $locale),
            ReminderKind::ChooseCourse => __('reminders.choose_course', [], $locale),
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
