<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\DTO\ReminderQuizVerdict;
use App\Domain\Learners\Enums\ReminderQuizStatus;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Grades a tap on the option of a reminder question (docs/12 §7.5, docs/04 B.5a).
 *
 * The answer is NOT a repetition: nothing is written to `word_repetitions`, `training_sessions` / `training_reviews` or
 * `learner_daily_stats` (so no streak, no goal of the day, no league points, no SM-2 step) — only the state of the question
 * (the picked option, once) and a `reminder.quiz_answered` event. One question gives one answer of four options: too thin
 * a signal to move an interval, and a tap in a chat must not be a way to farm points.
 *
 * Under a per-learner lock, so two taps (a double tap, a retried webhook update) cannot both be "the first": the first
 * records the pick and the event; any later one gets the SAME verdict (that of the first pick) with `repeated = true`, no
 * second event — the caller edits the message again, which is how a lost edit heals. A token that is not the state's
 * (an old question, a forwarded message, a made-up button), a missing or expired state: `Expired`, nothing changes.
 */
final class ReminderQuizAnswerService
{
    public function __construct(
        private readonly ReminderQuizStore $store,
        private readonly LearnerStatsService $stats,
        private readonly ProductEventRecorder $events,
    ) {}

    /** @throws LockTimeoutException when another tap of the same learner holds the lock for more than 5 seconds */
    public function answer(Learner $learner, string $token, int $index, ?CarbonImmutable $now = null): ReminderQuizVerdict
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return Cache::lock("reminders:quiz:answer:{$learner->id}", 10)->block(5, function () use ($learner, $token, $index, $now): ReminderQuizVerdict {
            $state = $this->store->get($learner->id);

            if ($state === null || $state['expires_at'] <= $now->getTimestamp()) {
                return $this->expired($learner, 'missing');
            }

            if (! hash_equals($state['token'], $token) || $index < 0 || $index >= count($state['options'])) {
                return $this->expired($learner, 'token_mismatch');
            }

            $repeated = $state['answered_at'] !== null && $state['picked'] !== null;
            $picked = $repeated ? (int) $state['picked'] : $index;
            $correct = $picked === $state['correct'];

            if (! $repeated) {
                // A newer question was stored while this tap was handled: the tap belongs to the old one.
                if (! $this->store->markAnswered($learner->id, $state, $picked, $now)) {
                    return $this->expired($learner, 'token_mismatch');
                }
                $this->events->record(
                    ProductEventName::ReminderQuizAnswered,
                    [
                        'kind' => $state['kind'],
                        'word_id' => $state['word_id'],
                        'exercise' => $state['exercise'],
                        'correct' => $correct,
                        'picked' => $picked,
                        'latency_s' => max(0, $now->getTimestamp() - $state['sent_at']),
                        'due_count' => $state['due_count'],
                    ],
                    learner: $learner,
                );
            }

            return new ReminderQuizVerdict(
                status: ReminderQuizStatus::Accepted,
                correct: $correct,
                word: $state['word'],
                answer: $state['answer'],
                // Live: what is left to do in the app now, not what the reminder said this morning.
                dueCount: $this->stats->dueCount($learner, $now),
                kind: $state['kind'],
                repeated: $repeated,
            );
        });
    }

    /** A tap that cannot be answered: the question is gone or is not the one on the button. */
    public function expire(Learner $learner, string $reason): void
    {
        $this->events->record(ProductEventName::ReminderQuizExpired, ['reason' => $reason], learner: $learner);
    }

    private function expired(Learner $learner, string $reason): ReminderQuizVerdict
    {
        $this->expire($learner, $reason);

        return ReminderQuizVerdict::expired();
    }
}
