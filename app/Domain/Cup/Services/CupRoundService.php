<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupAnswerOutcome;
use App\Domain\Cup\DTO\CupDaySnapshot;
use App\Domain\Cup\DTO\CupRoundStart;
use App\Domain\Cup\DTO\CupRoundView;
use App\Domain\Cup\Enums\CupAttemptFinishReason;
use App\Domain\Cup\Enums\CupAttemptStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupSeeds;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Runs\RunSnapshotBuilder;
use App\Domain\Game\Services\Tasks\TaskRegistry;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Support\IsoTime;
use App\Domain\Practice\Support\JsonbKeyOrder;
use App\Domain\Practice\Support\SeededShuffle;
use App\Models\Course;
use App\Models\Cup;
use App\Models\CupDay;
use App\Models\CupParticipant;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

/**
 * The round of the day of a cup (docs/33 §5.1-§5.3, K5), built on the engine of the game: the snapshot of a day is
 * {@see RunSnapshotBuilder::forCupDay()} on the planned words, a task is graded by the game's handlers ({@see TaskRegistry}), the
 * state is a `RunState`. Nothing of the game's economy is touched - no lives, coins, league points, SRS, daily stats, "one active
 * run": the cup has its own tables and writes only them (docs/33 В3, owner's К3; no word points either, docs/32 §3.2).
 *
 * - **One attempt per participant and day:** the unique `(cup_day_id, participant_id)`; a second tab, a double tap or a racing start get
 *   the same attempt (`INSERT … ON CONFLICT DO NOTHING RETURNING id`, then the row).
 * - **The same words for everybody, a different order for each:** the day's snapshot is built once, under `FOR UPDATE` of its row
 *   (two first starts at once make one snapshot); the order of the tasks is `SeededShuffle::order(HMAC(day seed, participant))`, the
 *   options are shuffled at the projection.
 * - **Nothing from the client is a fact:** the time is the server's, the deadline `expires_at` is fixed at the start, the points come
 *   from the verdict of the snapshot; an answer is accepted until `expires_at + grace` (1.5 s), later the attempt closes as `expired`
 *   in the same transaction (the points of earlier answers stay). A task is answered once (`answer_already_accepted`), in the order of
 *   the attempt (`element_not_current`); a repeated `request_id` returns the stored body and changes nothing. A stranger's attempt is
 *   404, the same as a missing one. There are no hints in the cup; a skip is an answer worth 0; a typo is 0 (owner's К4).
 *
 * Locks of an answer: `cup_round_attempts` (FOR UPDATE OF the attempt only) → `cup_participants` (FOR UPDATE, only for a right answer)
 * → `cup_day_scores` (upsert). The row of the cup is never locked by an answer: the status is re-read under the lock of the
 * participant, which the finish takes after the cup.
 */
final class CupRoundService
{
    public function __construct(
        private readonly RunSnapshotBuilder $builder,
        private readonly TaskRegistry $tasks,
        private readonly CupProjection $projection,
        private readonly CupMembershipGate $gate,
        private readonly CupScoreBook $scores,
        private readonly CupStandings $standings,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * "Play today" (`POST /cups/{id}/rounds/today`): the attempt already made today is returned as it is (no call to Telegram);
     * otherwise the cup must be running and not paused, there must be a current day the participant may play, and Telegram must say
     * he is in the chat right now.
     *
     * @throws CupException cup_unavailable | cup_not_open | cup_not_started | cup_closed | cup_not_member | cup_round_unavailable
     *                      | cup_round_done | cup_round_expired | cup_paused | cup_unverifiable | cup_check_busy | cup_material_unavailable
     */
    public function startToday(Learner $learner, Cup $cup, ?CarbonImmutable $now = null): CupRoundStart
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $group = $cup->telegramGroup;
        if (! CupAccess::allowsGroup($group)) {
            throw CupException::unavailable();
        }
        match ($cup->status) {
            CupStatus::Draft => throw CupException::notOpen(),
            CupStatus::Scheduled => throw CupException::notStarted(),
            CupStatus::Finished, CupStatus::Canceled => throw CupException::closed(),
            CupStatus::Running => null,
        };

        /** @var CupParticipant|null $participant */
        $participant = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->first();
        if ($participant === null) {
            throw CupException::notMember();
        }

        /** @var CupDay|null $day */
        $day = CupDay::query()
            ->select(['id', 'cup_id', 'day', 'starts_at', 'ends_at', 'built_at'])
            ->where('cup_id', $cup->id)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->first();
        if ($day === null || $day->day < $participant->first_day) {
            throw CupException::roundUnavailable();
        }

        $existing = CupRoundAttempt::query()->where('cup_day_id', $day->id)->where('participant_id', $participant->id)->first();
        if ($existing !== null) {
            return new CupRoundStart($this->resume($existing, $cup, $day, $now), false);
        }

        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            throw CupException::paused($pause->value);
        }
        $this->gate->admitToRound($cup, $participant, $learner, $now);

        $snapshot = $this->ensureDaySnapshot($day, $cup);
        $daySeed = CupSeeds::day($cup->seed_secret, $day->day);
        $ids = $snapshot->ids();
        $order = [];
        foreach (SeededShuffle::order(CupSeeds::order($daySeed, $participant->id), 'cup-order', count($ids)) as $index) {
            $order[] = $ids[$index];
        }
        $locale = $this->locales->forLearner($learner);
        if (! in_array($locale, $snapshot->locales, true)) {
            $locale = $snapshot->locales[0] ?? $this->locales->fallback();
        }
        $expiresAt = $now->addMinutes(max(1, (int) config('cup.round.minutes', 10)));

        /** @var array{0: CupRoundAttempt, 1: bool} $made */
        $made = DB::transaction(function () use ($cup, $day, $participant, $learner, $order, $locale, $now, $expiresAt): array {
            $state = RunState::initial(0)->toArray();
            $inserted = DB::selectOne(
                'INSERT INTO cup_round_attempts (cup_id, cup_day_id, participant_id, learner_id, "order", state, answered, correct, points, status, locale, started_at, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (cup_day_id, participant_id) DO NOTHING
                 RETURNING id',
                [
                    $cup->id, $day->id, $participant->id, $learner->id,
                    json_encode($order, JSON_THROW_ON_ERROR), json_encode($state, JSON_THROW_ON_ERROR),
                    CupAttemptStatus::Active->value, $locale,
                    $now->format('Y-m-d H:i:s'), $expiresAt->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
                ],
            );
            if ($inserted === null) {
                // A second tab / a racing start made it first: the same attempt.
                return [CupRoundAttempt::query()->where('cup_day_id', $day->id)->where('participant_id', $participant->id)->firstOrFail(), false];
            }

            $this->scores->openDay($cup->id, $participant->id, $day->day, $now);

            $attempt = (new CupRoundAttempt)->newFromBuilder([
                'id' => (int) $inserted->id,
                'cup_id' => $cup->id,
                'cup_day_id' => $day->id,
                'participant_id' => $participant->id,
                'learner_id' => $learner->id,
                'order' => json_encode($order, JSON_THROW_ON_ERROR),
                'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'answered' => 0,
                'correct' => 0,
                'points' => 0,
                'status' => CupAttemptStatus::Active->value,
                'finish_reason' => null,
                'locale' => $locale,
                'started_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'finished_at' => null,
            ]);

            return [$attempt, true];
        });

        [$attempt, $created] = $made;
        if (! $created) {
            return new CupRoundStart($this->resume($attempt, $cup, $day, $now), false);
        }

        $this->standings->forgetAfterCommit($cup->id);
        $this->events->record(ProductEventName::CupRoundStarted, ['cup_id' => $cup->id, 'attempt_id' => $attempt->id, 'day' => $day->day], learner: $learner);

        return new CupRoundStart($this->buildView($attempt, $day->day, $snapshot, $daySeed, $now, self::nextDayAt($day->day, $cup->days, $day->ends_at)), true);
    }

    /**
     * Resuming (`GET /cup-rounds/{id}`): the same attempt, its accepted answers, its ORIGINAL deadline; a reload resets nothing. An
     * attempt past its deadline is closed on the way (a conditional UPDATE). A stranger's or a missing attempt: 404.
     *
     * @throws CupException cup_round_not_found
     */
    public function find(Learner $learner, int $attemptId, ?CarbonImmutable $now = null): CupRoundView
    {
        $now ??= CarbonImmutable::now();
        $context = $this->context($learner, $attemptId, false) ?? throw CupException::roundNotFound();
        $attempt = $context['attempt'];
        if ($attempt->isActive() && $this->isPastDeadline($attempt, $now)) {
            $this->expireRow($attempt, $now);
            $attempt = CupRoundAttempt::query()->findOrFail($attempt->id);
        }

        return $this->buildView($attempt, $context['day'], $context['snapshot'], $context['day_seed'], $now, $context['next_day_at']);
    }

    /**
     * An answer to the current task (`POST /cup-rounds/{id}/answer`).
     *
     * @param  array<string, mixed>  $answer  `{option_id}` for a task with options, `{text}` for a typed one, `{skip: true}`
     *
     * @throws CupException cup_round_not_found | cup_closed | cup_round_done | cup_round_expired | unknown_element_id
     *                      | element_not_current | answer_already_accepted | invalid_answer_shape | unknown_option_id
     */
    public function answer(Learner $learner, int $attemptId, string $requestId, string $elementId, array $answer, ?CarbonImmutable $now = null): CupAnswerOutcome
    {
        $now ??= CarbonImmutable::now();
        $requestId = strtolower($requestId);

        /** @var array{0: array<string, mixed>, 1: bool, 2: array{attempt: CupRoundAttempt, day: int, snapshot: CupDaySnapshot, day_seed: string, cup_status: string, counted_days: int}, 3: bool}|CupException $outcome */
        $outcome = DB::transaction(function () use ($learner, $attemptId, $requestId, $elementId, $answer, $now): array|CupException {
            $context = $this->context($learner, $attemptId, true);
            if ($context === null) {
                return CupException::roundNotFound();
            }
            $attempt = $context['attempt'];

            /** @var mixed $prior */
            $prior = DB::table('cup_round_answers')->where('attempt_id', $attempt->id)->where('request_id', $requestId)->value('result');
            if ($prior !== null) {
                return [self::decode($prior), true, $context, false];
            }

            if ($context['cup_status'] !== CupStatus::Running->value) {
                return CupException::closed();
            }
            if (! $attempt->isActive()) {
                return $attempt->finish_reason === CupAttemptFinishReason::Expired ? CupException::roundExpired($attempt->points) : CupException::roundDone($attempt->points);
            }
            if ($this->isPastDeadline($attempt, $now)) {
                $this->close($attempt, CupAttemptFinishReason::Expired, $now, []);

                return CupException::roundExpired($attempt->points);
            }

            [$body, $finished] = $this->accept($attempt, $context, $elementId, $answer, $requestId, $now);

            return [$body, false, $context, $finished];
        });

        // An error raised AFTER the commit, so that the closing of an expired attempt is kept.
        if ($outcome instanceof CupException) {
            throw $outcome;
        }
        [$body, $replayed, $context, $finished] = $outcome;
        $attempt = $context['attempt'];
        if ($finished) {
            $this->recordFinished($attempt, $context['day'], $learner);
        }

        return new CupAnswerOutcome($body, $this->buildView($attempt, $context['day'], $context['snapshot'], $context['day_seed'], $now, $context['next_day_at']), $replayed);
    }

    /**
     * The state of today for a participant (`GET /cups/{id}/rounds/today`, the `me.today` of the table) - no write, no Telegram.
     *
     * @return array{state: 'not_started'|'active'|'done'|'not_available', day: int|null, attempt_id: int|null, points: int|null, expires_at: string|null}
     */
    public function today(Cup $cup, ?CupParticipant $participant, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $none = ['state' => 'not_available', 'day' => null, 'attempt_id' => null, 'points' => null, 'expires_at' => null];
        if ($participant === null || $cup->status !== CupStatus::Running) {
            return $none;
        }
        $day = CupDay::query()->select(['id', 'day'])->where('cup_id', $cup->id)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first();
        if ($day === null || $day->day < $participant->first_day) {
            return $none;
        }
        $attempt = CupRoundAttempt::query()
            ->select(['id', 'status', 'points', 'expires_at'])
            ->where('cup_day_id', $day->id)->where('participant_id', $participant->id)->first();
        if ($attempt === null) {
            return ['state' => 'not_started', 'day' => $day->day, 'attempt_id' => null, 'points' => null, 'expires_at' => null];
        }
        $active = $attempt->isActive() && ! $this->isPastDeadline($attempt, $now);

        return [
            'state' => $active ? 'active' : 'done',
            'day' => $day->day,
            'attempt_id' => $attempt->id,
            'points' => $attempt->points,
            'expires_at' => $active ? IsoTime::format($attempt->expires_at) : null,
        ];
    }

    /**
     * The clip of a `listen` task of the learner's own attempt (for the audio route of K7): the disk and the path from the snapshot,
     * null for another kind, a stranger's attempt or an unknown task.
     *
     * @return array{disk: string, path: string}|null
     */
    public function audioOf(Learner $learner, int $attemptId, string $elementId): ?array
    {
        $context = $this->context($learner, $attemptId, false);
        $element = $context === null || ! in_array($elementId, $context['attempt']->order, true) ? null : $context['snapshot']->element($elementId);
        $path = $element?->get('audio_path');
        if ($element === null || ! is_string($path) || $path === '') {
            return null;
        }

        return ['disk' => (string) $element->get('audio_disk', config('twa.tts.audio_disk', 'public')), 'path' => $path];
    }

    /**
     * The snapshot of the day: read when built; else built ONCE under `FOR UPDATE` of the row of the day - the scheduler builds it an
     * hour before the day, this is the lazy path of the first start (two first starts at once: the second waits and reads).
     *
     * @throws CupException cup_material_unavailable
     */
    public function ensureDaySnapshot(CupDay $day, Cup $cup): CupDaySnapshot
    {
        if ($day->built_at !== null) {
            $attributes = $day->getAttributes();
            $raw = array_key_exists('snapshot', $attributes) ? $attributes['snapshot'] : DB::table('cup_days')->where('id', $day->id)->value('snapshot');
            if ($raw !== null) {
                return CupDaySnapshot::fromArray(self::decode($raw));
            }
        }

        return DB::transaction(function () use ($day, $cup): CupDaySnapshot {
            $row = DB::table('cup_days')->where('id', $day->id)->lockForUpdate()->first(['id', 'day', 'snapshot', 'built_at']);
            if ($row === null) {
                throw new LogicException("The day {$day->id} of the cup {$cup->id} is gone.");
            }
            if ($row->snapshot !== null) {
                return CupDaySnapshot::fromArray(self::decode($row->snapshot));
            }
            if ($row->built_at !== null) {
                // The day was closed without a snapshot (the cup was canceled or finished): nothing to play.
                throw CupException::roundUnavailable();
            }

            $snapshot = $this->build($cup, (int) $row->day);
            $now = CarbonImmutable::now();
            DB::table('cup_days')->where('id', $day->id)->update([
                'snapshot' => json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'built_at' => $now,
                'updated_at' => $now,
            ]);

            return $snapshot;
        });
    }

    /**
     * The scheduler: the snapshots of the days that begin within `snapshot_lead_minutes`, of running cups, oldest first.
     *
     * @return int the days built
     */
    public function buildUpcoming(CarbonImmutable $now, int $batch): int
    {
        $lead = $now->addMinutes(max(0, (int) config('cup.snapshot_lead_minutes', 60)));
        $days = CupDay::query()
            ->select(['cup_days.id', 'cup_days.cup_id', 'cup_days.day', 'cup_days.built_at'])
            ->join('cups', 'cups.id', '=', 'cup_days.cup_id')
            ->whereNull('cup_days.built_at')
            ->where('cup_days.starts_at', '<=', $lead)
            ->where('cups.status', CupStatus::Running->value)
            ->orderBy('cup_days.starts_at')
            ->limit($batch)
            ->get();
        if ($days->isEmpty()) {
            return 0;
        }
        $cups = Cup::query()->whereIn('id', $days->pluck('cup_id')->unique()->all())->get()->keyBy('id');

        $built = 0;
        foreach ($days as $day) {
            $cup = $cups->get($day->cup_id);
            if ($cup === null) {
                continue;
            }
            try {
                $this->ensureDaySnapshot($day, $cup);
                $built++;
            } catch (CupException $e) {
                report($e);
            }
        }

        return $built;
    }

    /**
     * The scheduler: closes the attempts left open past their deadline (+ grace), by a conditional UPDATE - an answer holding the row
     * finishes first and the UPDATE re-checks `status = 'active'` on the row it waited for.
     *
     * @return int the attempts closed
     */
    public function closeExpired(CarbonImmutable $now, int $batch): int
    {
        $limit = $now->subMilliseconds($this->graceMs())->format('Y-m-d H:i:s');
        $ids = DB::table('cup_round_attempts')
            ->where('status', CupAttemptStatus::Active->value)
            ->where('expires_at', '<', $limit)
            ->orderBy('expires_at')
            ->limit($batch)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $closed = DB::select(
            "UPDATE cup_round_attempts SET status = ?, finish_reason = ?, finished_at = ?, updated_at = ?
             WHERE id IN ({$placeholders}) AND status = ? AND expires_at < ?
             RETURNING id, cup_id, learner_id, cup_day_id, points",
            [CupAttemptStatus::Finished->value, CupAttemptFinishReason::Expired->value, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), ...$ids, CupAttemptStatus::Active->value, $limit],
        );

        foreach ($closed as $row) {
            $this->events->record(ProductEventName::CupRoundFinished, [
                'cup_id' => (int) $row->cup_id,
                'attempt_id' => (int) $row->id,
                'points' => (int) $row->points,
                'reason' => CupAttemptFinishReason::Expired->value,
            ]);
        }

        return count($closed);
    }

    // ------------------------------------------------------------------ under the lock of the attempt

    /**
     * @param  array{attempt: CupRoundAttempt, day: int, snapshot: CupDaySnapshot, day_seed: string, cup_status: string, counted_days: int}  $context
     * @param  array<string, mixed>  $answer
     * @return array{0: array<string, mixed>, 1: bool} the stored body, whether the attempt is finished
     */
    private function accept(CupRoundAttempt $attempt, array $context, string $elementId, array $answer, string $requestId, CarbonImmutable $now): array
    {
        $snapshot = $context['snapshot'];
        $element = $snapshot->element($elementId);
        if ($element === null || ! in_array($elementId, $attempt->order, true)) {
            throw CupException::unknownElementId();
        }
        $state = RunState::fromArray($attempt->state);
        if ($state->isAnswered($elementId)) {
            throw CupException::answerAlreadyAccepted((string) $state->verdictOf($elementId)?->value);
        }
        $current = $attempt->order[$state->current] ?? '';
        if ($current !== $elementId) {
            throw CupException::elementNotCurrent($current);
        }

        try {
            $grade = $this->tasks->forElement($element)->grade($element, $answer, $state);
        } catch (GameException $e) {
            throw match ($e->errorCode) {
                GameException::UNKNOWN_OPTION_ID => CupException::unknownOptionId(),
                default => CupException::invalidAnswerShape(),
            };
        }

        // Owner's К4: only a clean right answer counts - a typo, a wrong answer and a skip are worth 0.
        $points = $grade->verdict === Verdict::Correct ? 2 : 0;
        $state->markAnswered($elementId, $grade->verdict, true, $points, false, $grade->normalizedAnswer, IsoTime::format($now));
        $state->current++;
        $finished = $state->current >= count($attempt->order);
        $totalPoints = $attempt->points + $points;

        $body = JsonbKeyOrder::object([
            'verdict' => $grade->verdict->value,
            'points' => $points,
            'reveal' => $this->projection->reveal($element, $attempt->locale, $state),
            'finished' => $finished,
            'total_points' => $totalPoints,
        ]);

        DB::table('cup_round_answers')->insert([
            'attempt_id' => $attempt->id,
            'element_id' => $elementId,
            'request_id' => $requestId,
            'payload' => json_encode($grade->normalizedAnswer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'verdict' => $grade->verdict->value,
            'points' => $points,
            'result' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'accepted_at' => $now,
            'created_at' => $now,
        ]);

        $attempt->forceFill([
            'state' => $state->toArray(),
            'answered' => $attempt->answered + 1,
            'correct' => $attempt->correct + ($points > 0 ? 1 : 0),
            'points' => $totalPoints,
        ]);
        if ($finished) {
            $this->close($attempt, CupAttemptFinishReason::Completed, $now, $attempt->getDirty());
        } else {
            $this->save($attempt, $attempt->getDirty(), $now);
        }

        if ($points > 0) {
            $this->credit($attempt, $context['day'], $points, $context['counted_days'], $now);
        }

        return [$body, $finished];
    }

    /**
     * The points of a right answer into the day of the ATTEMPT (an attempt started at 23:58 scores for the day it started): under the
     * lock of the participant, after re-reading the status of the cup - a finish that got there first leaves the points out.
     */
    private function credit(CupRoundAttempt $attempt, int $day, int $points, int $countedDays, CarbonImmutable $now): void
    {
        DB::table('cup_participants')->where('id', $attempt->participant_id)->lockForUpdate()->value('id');
        // A SEPARATE statement after the lock: a join locked `FOR UPDATE OF cup_participants` would re-check the participant's row
        // after a finish but keep the OLD row of the cup (READ COMMITTED re-evaluates only the locked rows) and see it running.
        $status = DB::table('cups')->where('id', $attempt->cup_id)->value('status');
        if ($status !== CupStatus::Running->value) {
            return;
        }

        $this->scores->addRoundPoints($attempt->cup_id, $attempt->participant_id, $day, $points, $countedDays, $now);
        $this->standings->forgetAfterCommit($attempt->cup_id);
    }

    /** @param array<string, mixed> $dirty */
    private function close(CupRoundAttempt $attempt, CupAttemptFinishReason $reason, CarbonImmutable $now, array $dirty): void
    {
        $attempt->forceFill(['status' => CupAttemptStatus::Finished, 'finish_reason' => $reason, 'finished_at' => $now]);
        $this->save($attempt, [...$dirty, ...$attempt->getDirty()], $now);
    }

    /**
     * One UPDATE of the changed columns (the model was hydrated from a joined row, so it is written through the query builder).
     *
     * @param  array<string, mixed>  $dirty
     */
    private function save(CupRoundAttempt $attempt, array $dirty, CarbonImmutable $now): void
    {
        $values = [];
        foreach ($dirty as $column => $value) {
            $values[$column] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => $value,
            };
        }
        $values['updated_at'] = $now;
        DB::table('cup_round_attempts')->where('id', $attempt->id)->update($values);
        $attempt->syncOriginal();
    }

    private function expireRow(CupRoundAttempt $attempt, CarbonImmutable $now): void
    {
        $changed = DB::table('cup_round_attempts')
            ->where('id', $attempt->id)
            ->where('status', CupAttemptStatus::Active->value)
            ->update(['status' => CupAttemptStatus::Finished->value, 'finish_reason' => CupAttemptFinishReason::Expired->value, 'finished_at' => $now, 'updated_at' => $now]);
        if ($changed > 0) {
            $this->events->record(ProductEventName::CupRoundFinished, ['cup_id' => $attempt->cup_id, 'attempt_id' => $attempt->id, 'points' => $attempt->points, 'reason' => CupAttemptFinishReason::Expired->value]);
        }
    }

    private function recordFinished(CupRoundAttempt $attempt, int $day, Learner $learner): void
    {
        $this->events->record(ProductEventName::CupRoundFinished, [
            'cup_id' => $attempt->cup_id,
            'attempt_id' => $attempt->id,
            'day' => $day,
            'points' => $attempt->points,
            'reason' => $attempt->finish_reason?->value,
        ], learner: $learner);
    }

    // ------------------------------------------------------------------ reading

    /**
     * The attempt of the learner with its day, its snapshot and the status of its cup, in ONE statement; with `$lock`, `FOR UPDATE OF`
     * the attempt alone (never the day or the cup). Null for a stranger's or a missing attempt.
     *
     * @return array{attempt: CupRoundAttempt, day: int, snapshot: CupDaySnapshot, day_seed: string, cup_status: string, counted_days: int}|null
     */
    private function context(Learner $learner, int $attemptId, bool $lock): ?array
    {
        $query = DB::table('cup_round_attempts')
            ->join('cup_days', 'cup_days.id', '=', 'cup_round_attempts.cup_day_id')
            ->join('cups', 'cups.id', '=', 'cup_round_attempts.cup_id')
            ->where('cup_round_attempts.id', $attemptId)
            ->where('cup_round_attempts.learner_id', $learner->id)
            ->select([
                'cup_round_attempts.*',
                'cup_days.day as ctx_day',
                'cup_days.snapshot as ctx_snapshot',
                'cups.status as ctx_cup_status',
                'cups.seed_secret as ctx_secret',
                'cups.counted_days as ctx_counted_days',
                'cups.days as ctx_days',
                'cup_days.ends_at as ctx_day_ends_at',
            ]);
        if ($lock) {
            $query->lock(DB::getDriverName() === 'pgsql' ? 'for update of cup_round_attempts' : true);
        }
        $row = $query->first();
        if ($row === null || $row->ctx_snapshot === null) {
            return null;
        }

        $attributes = (array) $row;
        $day = (int) $attributes['ctx_day'];
        $snapshot = CupDaySnapshot::fromArray(self::decode($attributes['ctx_snapshot']));
        $context = [
            'day' => $day,
            'snapshot' => $snapshot,
            'day_seed' => CupSeeds::day((string) $attributes['ctx_secret'], $day),
            'cup_status' => (string) $attributes['ctx_cup_status'],
            'counted_days' => (int) $attributes['ctx_counted_days'],
            'next_day_at' => self::nextDayAt($day, (int) $attributes['ctx_days'], CarbonImmutable::parse((string) $attributes['ctx_day_ends_at'], 'UTC')),
        ];
        foreach (array_keys($attributes) as $key) {
            if (str_starts_with((string) $key, 'ctx_')) {
                unset($attributes[$key]);
            }
        }

        return ['attempt' => (new CupRoundAttempt)->newFromBuilder($attributes), ...$context];
    }

    /** The attempt of today returned to a second start: closed on the way if past its deadline; a finished one is refused. */
    private function resume(CupRoundAttempt $attempt, Cup $cup, CupDay $day, CarbonImmutable $now): CupRoundView
    {
        if ($attempt->isActive() && $this->isPastDeadline($attempt, $now)) {
            $this->expireRow($attempt, $now);
            $attempt->forceFill(['status' => CupAttemptStatus::Finished, 'finish_reason' => CupAttemptFinishReason::Expired]);
        }
        if (! $attempt->isActive()) {
            throw CupException::roundDone($attempt->points);
        }

        return $this->buildView($attempt, $day->day, $this->ensureDaySnapshot($day, $cup), CupSeeds::day($cup->seed_secret, $day->day), $now, self::nextDayAt($day->day, $cup->days, $day->ends_at));
    }

    /**
     * When the round of the next day opens (docs/04 A.23 `next_day_at`): the end of the attempt's day - the local midnight of the cup,
     * kept in UTC by the row of the day, so a change of the clocks is the server's business - or null on the last day.
     */
    private static function nextDayAt(int $day, int $days, ?CarbonInterface $dayEndsAt): ?string
    {
        return $day < $days && $dayEndsAt !== null ? IsoTime::format($dayEndsAt) : null;
    }

    private function buildView(CupRoundAttempt $attempt, int $day, CupDaySnapshot $snapshot, string $daySeed, CarbonImmutable $now, ?string $nextDayAt): CupRoundView
    {
        $state = RunState::fromArray($attempt->state);
        $order = $attempt->order;
        $total = count($order);
        $active = $attempt->isActive();

        $current = null;
        if ($active && $state->current < $total) {
            $element = $snapshot->element($order[$state->current]);
            if ($element !== null) {
                $current = ['position' => $state->current + 1, ...$this->projection->project($element, $attempt->locale, $state, $daySeed, $attempt->participant_id)];
            }
        }

        $answers = [];
        $review = $active ? null : [];
        foreach ($order as $id) {
            $entry = $state->entry($id);
            $answeredIt = ($entry['status'] ?? null) === RunState::STATUS_ANSWERED;
            if ($answeredIt) {
                $answers[] = ['element_id' => $id, 'verdict' => (string) ($entry['verdict'] ?? ''), 'points' => (int) ($entry['points'] ?? 0)];
            }
            $element = $review === null ? null : $snapshot->element($id);
            if ($review !== null && $element !== null) {
                $review[] = [
                    'element_id' => $id,
                    'kind' => $element->kind,
                    'verdict' => $answeredIt ? (string) ($entry['verdict'] ?? '') : null,
                    'points' => $answeredIt ? (int) ($entry['points'] ?? 0) : 0,
                    'answer' => $answeredIt && is_array($entry['answer'] ?? null) ? $entry['answer'] : null,
                    'reveal' => $this->projection->reveal($element, $attempt->locale, $state),
                ];
            }
        }

        return new CupRoundView(
            attemptId: $attempt->id,
            cupId: $attempt->cup_id,
            participantId: $attempt->participant_id,
            day: $day,
            status: $attempt->status->value,
            finishReason: $attempt->finish_reason?->value,
            total: $total,
            answered: $attempt->answered,
            correct: $attempt->correct,
            points: $attempt->points,
            maxPoints: 2 * $total,
            position: min($state->current + 1, $total),
            startedAt: IsoTime::format($attempt->started_at),
            expiresAt: IsoTime::format($attempt->expires_at),
            serverNow: IsoTime::format($now),
            current: $current,
            answers: $answers,
            review: $review,
            nextDayAt: $nextDayAt,
        );
    }

    private function isPastDeadline(CupRoundAttempt $attempt, CarbonImmutable $now): bool
    {
        return $now->gt(CarbonImmutable::instance($attempt->expires_at)->addMilliseconds($this->graceMs()));
    }

    private function graceMs(): int
    {
        return max(0, (int) config('cup.round.grace_ms', 1500));
    }

    /** @throws CupException cup_material_unavailable */
    private function build(Cup $cup, int $day): CupDaySnapshot
    {
        $plan = $cup->word_plan ?? [];
        $words = [];
        foreach ((array) ($plan[$day] ?? $plan[(string) $day] ?? []) as $id) {
            $words[] = (int) $id;
        }
        $course = Course::query()->find($cup->course_id);
        if ($words === [] || $course === null) {
            throw CupException::materialUnavailable();
        }
        $locales = new LocaleSet($this->locales->supported());

        try {
            $composition = $this->builder->forCupDay($course, $words, $locales, CupSeeds::day($cup->seed_secret, $day), $cup->round_tasks);
        } catch (GameException) {
            throw CupException::materialUnavailable();
        }

        return new CupDaySnapshot($locales->locales, $composition->elements, $course->slug);
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }
        try {
            $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
