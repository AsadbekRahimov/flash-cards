<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\DTO\DayBounds;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\LearnerRowLock;
use App\Domain\Learning\DTO\HardRoundAvailability;
use App\Domain\Learning\DTO\LearnerExerciseSettings;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\HardRoundException;
use App\Domain\Learning\Support\HardRoundPolicy;
use App\Domain\Operations\Support\ScalarBatch;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The round of hard words (`hard_review`, docs/06 §14): an occasional round over the 8-10 words the learner stumbles on, offered once
 * per local day. Its answers are ordinary repetitions ({@see ReviewSubmissionService}, the schedulers untouched: points, streak and
 * the day's statistics come with them); what is special is only the SET of the round and the way it is opened.
 *
 * A candidate is a repetition of an active word that was released (`review` / `relearning`), is hard (`is_hard`) or failed
 * `hard_round.lapses_from` times or more, is due within `hard_round.due_within_days` days (`next_review_at <= now + N days`: the round
 * never pulls a word more than that ahead of its schedule, so SM-2, which multiplies the stored interval whatever the elapsed time,
 * grows it as the ordinary schedule would, and a word that was answered right leaves the pool until its term), and was NOT graded in
 * the learner's current day window - a word cannot be pumped for points twice in a day (once in a daily round, once here). The order is that of the game's boss: hard first, most lapses, lowest
 * ease, the longest overdue. One statement, served by `word_repetitions_hard_idx` (the predicate is written exactly as the index has it).
 *
 * Opening ({@see open()}) is one transaction under the learner lock (the first lock, as the picker's): the live round begun today is
 * returned (two tabs get one session), a stale one is closed `expired`; the day's limit (`per_day`) is checked; the set is fixed in
 * `training_sessions.meta.hard_words`; and the words of the set are brought to "due now" ({@see ReviewPull}, without marking them
 * hard) - after that the ordinary path of a due card serves and accepts them. A round that is abandoned leaves them due: accepted
 * (docs/06 §14, the same price as the game's pull). Refusals are thrown after the commit ({@see HardRoundException}).
 *
 * Lock order: `learners (FOR NO KEY UPDATE) -> training_sessions -> word_repetitions`. The transaction of a grade starts at
 * `training_sessions` and never takes `learners`, so there is no cycle.
 */
final class HardRoundService
{
    public function __construct(
        private readonly HardRoundPolicy $policy,
        private readonly LearnerClock $clock,
        private readonly TrainingSessionService $sessions,
        private readonly ReviewPull $pull,
        private readonly ProductEventRecorder $events,
        private readonly EntitlementResolver $entitlements,
        private readonly ExerciseSelector $selector,
    ) {}

    public function enabled(): bool
    {
        return $this->policy->enabled;
    }

    /** The learner's day window the limit and "graded today" are counted in (a timezone change carries the running day over). */
    public function window(Learner $learner, ?CarbonImmutable $now = null): DayBounds
    {
        return $this->clock->limitWindowUtc($learner, $now);
    }

    /**
     * @param  iterable<TrainingSession>|null  $today  the `hard_review` sessions begun in the window, when the caller read them already
     *                                                 (columns id, status, started_at, expires_at, completed_at, meta); read here otherwise
     * @param  Entitlement|null  $entitlement  the learner's plan when the caller resolved it already
     * @param  int|null  $candidates  how many candidates (up to the size of the round) the caller counted already, in a statement of its
     *                                own ({@see candidatesQuery()}); null = counted here when the answer needs them
     */
    public function availability(Learner $learner, ?CarbonImmutable $now = null, ?iterable $today = null, ?Entitlement $entitlement = null, ?int $candidates = null): HardRoundAvailability
    {
        $now ??= CarbonImmutable::now();

        if (! $this->policy->enabled) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_DISABLED);
        }

        $settled = $this->settledWithoutCandidates($learner, $now, $today, $entitlement);
        if ($settled !== null) {
            return $settled;
        }

        $found = $candidates ?? count($this->candidates((int) $learner->id, $this->window($learner, $now)->startUtc, $now, $this->policy->words));
        if ($found < $this->policy->minWords) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_NOT_ENOUGH);
        }

        return new HardRoundAvailability(true, null, $found);
    }

    /**
     * Whether {@see availability()} would have to count the candidates for this learner now: no live round, the day's limit not reached,
     * an exercise to make the round of. A caller that reads several counters in one statement adds {@see candidatesQuery()} to it only
     * then (the Today screen, docs/19 K10).
     *
     * @param  iterable<TrainingSession>  $today  the `hard_review` sessions begun in the window
     */
    public function needsCandidates(Learner $learner, CarbonImmutable $now, iterable $today, ?Entitlement $entitlement = null): bool
    {
        return $this->policy->enabled && $this->settledWithoutCandidates($learner, $now, $today, $entitlement) === null;
    }

    /**
     * The part of the answer that needs no candidates: the live round, the limit of the day, the exercise. Null = the candidates decide.
     *
     * @param  iterable<TrainingSession>|null  $today
     */
    private function settledWithoutCandidates(Learner $learner, CarbonImmutable $now, ?iterable $today, ?Entitlement $entitlement): ?HardRoundAvailability
    {
        $window = $this->window($learner, $now);
        $sessions = [];
        foreach ($today ?? $this->windowSessions($learner, $window->startUtc) as $session) {
            $sessions[] = $session;
        }

        foreach ($sessions as $session) {
            if ($session->status === 'open' && $session->completed_at === null && $session->isLiveAt($now)) {
                return new HardRoundAvailability(true, null, $this->wordCount($session), (int) $session->id);
            }
        }

        if (count($sessions) >= $this->policy->perDay) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_PLAYED_TODAY, resetsAt: $window->endUtc);
        }

        if (! $this->hasObjectiveExercise($learner, $now, $entitlement)) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_NO_OBJECTIVE_EXERCISE);
        }

        return null;
    }

    /**
     * The words of the round, hardest first: ONE statement.
     *
     * @return list<int>
     */
    public function candidates(int $learnerId, CarbonImmutable $windowStart, CarbonImmutable $now, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = $this->candidatesQuery($learnerId, $windowStart, $now, $limit)
            ->pluck('wr.word_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * The candidates of the round the learner would open now (`wr.word_id`, hardest first, up to the size of the round) as a query -
     * for a caller that counts them in one statement with other counters (`select count(*) from (...)`, {@see ScalarBatch::countLimited()}).
     */
    public function candidatesQueryFor(Learner $learner, CarbonImmutable $now): QueryBuilder
    {
        return $this->candidatesQuery((int) $learner->id, $this->window($learner, $now)->startUtc, $now, $this->policy->words);
    }

    private function candidatesQuery(int $learnerId, CarbonImmutable $windowStart, CarbonImmutable $now, int $limit): QueryBuilder
    {
        $horizon = $now->addDays($this->policy->dueWithinDays);

        return DB::table('word_repetitions as wr')
            ->join('words as w', fn ($join) => $join->on('w.id', '=', 'wr.word_id')->where('w.is_active', true))
            ->where('wr.learner_id', $learnerId)
            // The predicate of `word_repetitions_hard_idx`, constants as in the index: the planner proves it only for the same text.
            ->whereRaw('(wr.is_hard = true or wr.lapses >= 1)')
            // The horizon: one rule for every candidate; the range the index is read over.
            ->where('wr.next_review_at', '<=', $horizon)
            ->where(fn (QueryBuilder $q) => $q->where('wr.is_hard', true)->orWhere('wr.lapses', '>=', $this->policy->lapsesFrom))
            ->whereIn('wr.state', [RepetitionState::Review->value, RepetitionState::Relearning->value])
            ->where(fn (QueryBuilder $q) => $q->whereNull('wr.last_reviewed_at')->orWhere('wr.last_reviewed_at', '<', $windowStart))
            ->orderByDesc('wr.is_hard')
            ->orderByDesc('wr.lapses')
            ->orderBy('wr.easiness_factor')
            ->orderBy('wr.next_review_at')
            ->orderBy('wr.id')
            ->limit($limit)
            ->select('wr.word_id');
    }

    /**
     * Opens (or returns) the learner's round of hard words.
     *
     * Idempotent: the live round begun today is returned as it is. Must not be called inside an outer transaction (the lost-race
     * recovery re-reads the winner's row after a unique violation).
     *
     * @param  string  $source  `self` | `finish` | ... (new sessions only)
     *
     * @throws HardRoundException disabled | not_enough | played_today | no_objective_exercise
     */
    public function open(Learner $learner, string $source = 'self', ?CarbonImmutable $now = null): TrainingSession
    {
        $now ??= CarbonImmutable::now();

        if (! $this->policy->enabled) {
            $this->refuse($learner, new HardRoundAvailability(false, HardRoundAvailability::REASON_DISABLED), $source);
        }

        $window = $this->window($learner, $now);

        try {
            $result = DB::transaction(fn (): TrainingSession|HardRoundAvailability => $this->openLocked($learner, $source, $now, $window));
        } catch (UniqueConstraintViolationException) {
            // Lost the race against an open that bypassed the learner lock (the partial unique index is the source of truth): the winner's row serves.
            $winner = $this->openRound($learner)->first();
            if ($winner === null) {
                throw new HardRoundException(new HardRoundAvailability(false, HardRoundAvailability::REASON_PLAYED_TODAY, resetsAt: $window->endUtc));
            }

            return $winner;
        }

        if ($result instanceof HardRoundAvailability) {
            $this->refuse($learner, $result, $source);
        }

        return $result;
    }

    private function openLocked(Learner $learner, string $source, CarbonImmutable $now, DayBounds $window): TrainingSession|HardRoundAvailability
    {
        LearnerRowLock::take((int) $learner->id);

        /** @var TrainingSession|null $existing */
        $existing = $this->openRound($learner)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->completed_at !== null) {
                // Played to the end but never closed: what `/finish` would have done.
                $this->sessions->finish($existing, TrainingSession::DONE_FINISHED, $now, $learner);
            } elseif (! $existing->isLiveAt($now) || $existing->started_at->lt($window->startUtc)) {
                // Past its expiry, or yesterday's, left in the middle: closed the way the scheduler closes it.
                $existing->update(['status' => 'closed', 'ended_at' => $now, 'done_reason' => TrainingSession::DONE_EXPIRED]);
            } else {
                return $existing;
            }
        }

        $played = TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', TrainingSession::MODE_HARD_REVIEW)
            ->where('started_at', '>=', $window->startUtc)
            ->count();
        if ($played >= $this->policy->perDay) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_PLAYED_TODAY, resetsAt: $window->endUtc);
        }

        if (! $this->hasObjectiveExercise($learner, $now)) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_NO_OBJECTIVE_EXERCISE);
        }

        $ids = $this->candidates((int) $learner->id, $window->startUtc, $now, $this->policy->words);
        if (count($ids) < $this->policy->minWords) {
            return new HardRoundAvailability(false, HardRoundAvailability::REASON_NOT_ENOUGH);
        }

        $attributes = [
            'learner_id' => $learner->id,
            'mode' => TrainingSession::MODE_HARD_REVIEW,
            'lesson_id' => null,
            'course_id' => null,
            'telegram_group_id' => null,
            'started_by_user_id' => null,
            'source' => $source,
            'status' => 'open',
            'started_at' => $now,
            'expires_at' => $now->addHours(max(1, (int) config('learning.session_ttl_hours', 12))),
            'meta' => ['hard_words' => $ids],
        ];
        TrainingSessionService::assertModeShape($attributes);

        $session = TrainingSession::query()->create($attributes);

        // The words become due now; `is_hard` stays as it is - nothing failed.
        $this->pull->toNow((int) $learner->id, $ids, $now, markHard: false);

        return $session;
    }

    private function refuse(Learner $learner, HardRoundAvailability $why, string $source): never
    {
        DB::afterCommit(fn () => $this->events->record(
            ProductEventName::HardRoundRefused,
            ['reason' => $why->reason, 'source' => $source],
            learner: $learner,
        ));

        throw new HardRoundException($why);
    }

    /** @return Builder<TrainingSession> */
    private function openRound(Learner $learner): Builder
    {
        return TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', TrainingSession::MODE_HARD_REVIEW)
            ->where('status', 'open');
    }

    /** @return Collection<int, TrainingSession> */
    private function windowSessions(Learner $learner, CarbonImmutable $windowStart): Collection
    {
        return TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', TrainingSession::MODE_HARD_REVIEW)
            ->where('started_at', '>=', $windowStart)
            ->get(['id', 'status', 'started_at', 'expires_at', 'completed_at', 'meta']);
    }

    /**
     * At least one exercise the round can be made of without typing: the meaning or the reverse (audio needs a file, not counted).
     * The same test as the training's ({@see ExerciseSelector::available()}): the config flags of the exercises, the plan's right to
     * the reverse, the learner's own switches.
     */
    private function hasObjectiveExercise(Learner $learner, CarbonImmutable $now, ?Entitlement $entitlement = null): bool
    {
        $settings = LearnerExerciseSettings::fromLearner($learner);
        $entitlement ??= $this->entitlements->for($learner, $now);

        foreach ([ExerciseType::Meaning, ExerciseType::Reverse] as $type) {
            if ($this->selector->available($type, $entitlement, $settings)) {
                return true;
            }
        }

        return false;
    }

    private function wordCount(TrainingSession $session): int
    {
        $words = ($session->meta ?? [])['hard_words'] ?? [];

        return is_array($words) ? count($words) : 0;
    }
}
