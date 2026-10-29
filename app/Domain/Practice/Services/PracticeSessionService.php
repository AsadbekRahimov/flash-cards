<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\AnswerOutcome;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\RetryMaterial;
use App\Domain\Practice\DTO\SessionView;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\DTO\SummaryView;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\Modes\PracticeModeHandler;
use App\Domain\Practice\Services\Modes\ReplaceableElements;
use App\Domain\Practice\Support\IsoTime;
use App\Domain\Practice\Support\JsonbKeyOrder;
use App\Models\Learner;
use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The solo practice session (docs/16 §1.3, §6): open / find / view / answer / replace / closeExpired.
 *
 * ONE active session per learner is a fact of the database (`active_learner_id` UNIQUE, NULL after the end). Everything
 * that changes a session happens under `FOR UPDATE` of its row, in the order of docs/16 §9: the session -> its attempts ->
 * the product events (AFTER the commit). The service is mode-agnostic: what an element looks like, what an answer means and
 * how it is graded is the {@see PracticeModeHandler} of the mode; the snapshot is the only source of truth while a game is on -
 * `practice_items` and `words` are not read.
 *
 * The answer (docs/16 §6.3), under the lock, in this order:
 *   1. a repeated `request_id` -> the stored `result` and the CURRENT view (even when the session has ended since: a lost
 *      answer to the last element must not turn into a 410 on the retry);
 *   2. an ended session -> 410; a session past `expires_at` is closed (`expired`) and answers 410;
 *   3. an unknown element -> 422; an element that is already answered -> 409 `answer_already_accepted` (with its verdict);
 *      an element that is not the current one -> 409 `element_not_current` (with the current id);
 *   4. the handler validates the shape and grades -> an attempt row, the new state, the counters, maybe the end.
 * (docs/16 §6.3 checks "not current" before "already accepted"; an element answered earlier is never the current one,
 * so that order would never say `answer_already_accepted`, which §14.2 expects for a second tab.)
 */
final class PracticeSessionService
{
    public function __construct(
        private readonly PracticeAccess $access,
        private readonly PracticeMaterialService $material,
        private readonly PracticeSnapshotBuilder $snapshots,
        private readonly ModeRegistry $modes,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Opens a solo session. Not inside an outer transaction: after a unique violation (two tabs) the winner is read again.
     *
     * - an active session and no `$replaceActive` -> 409 `practice_session_active` (with its details); with the flag it is
     *   finished (`replaced`); one past its `expires_at` is finished (`expired`) either way;
     * - `$retryOf`: the id of a FINISHED session of the learner; mode, course and lesson are taken from it, the material is its
     *   mistakes, the seed is new; nothing (enough) to repeat -> 409 `retry_not_available`;
     * - two tabs at once: the loser of the race gets the winner's session when it is the same practice (same mode, course,
     *   lesson), else the 409.
     *
     * @throws PracticeException practice_unavailable | practice_pro_required | practice_mode_disabled | course_not_found |
     *                           lesson_not_found | lesson_not_in_course | practice_not_enough_material | practice_session_active |
     *                           practice_session_not_found | retry_not_available
     */
    public function open(
        Learner $learner,
        PracticeMode $mode,
        string $courseSlug,
        ?int $lessonId,
        string $source,
        bool $replaceActive,
        ?int $retryOf,
        ?CarbonImmutable $now = null,
    ): PracticeSession {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($learner);

        $retrySource = $retryOf === null ? null : $this->retrySource($learner, $retryOf, $now);
        if ($retrySource !== null) {
            $mode = $retrySource->mode;
            $source = PracticeSession::SOURCE_RETRY;
        }
        $this->access->assertModeEnabled($mode);

        if ($retrySource !== null) {
            $course = $this->material->courseById($retrySource->course_id, $learner);
            $lesson = $retrySource->lesson_id === null ? null : $this->material->lesson($course, $retrySource->lesson_id, $learner);
        } else {
            $course = $this->material->course($courseSlug, $learner);
            $lesson = $lessonId === null ? null : $this->material->lesson($course, $lessonId, $learner);
        }

        $locale = $this->locales->forLearner($learner);
        $locales = new LocaleSet([$locale]);
        $scope = new MaterialScope($course, $lesson, $retrySource === null ? null : $this->retryMaterial($retrySource));

        $snapshot = $this->buildSnapshot($mode, $scope, $learner, $locales, $retrySource !== null);

        $attributes = [
            'learner_id' => $learner->id,
            'active_learner_id' => $learner->id,
            'mode' => $mode,
            'course_id' => $course->id,
            'lesson_id' => $lesson?->id,
            'source' => $source,
            'retry_of_session_id' => $retrySource?->id,
            'status' => SessionStatus::Active,
            'finish_reason' => null,
            'snapshot' => $snapshot->toArray(),
            'state' => self::initialState(),
            'total' => $snapshot->count(),
            'answered' => 0,
            'correct_first' => 0,
            'hints' => 0,
            'mismatches' => 0,
            'locale' => $locale,
            'expires_at' => $now->addHours($this->ttlHours()),
            'started_at' => $now,
            'finished_at' => null,
        ];
        self::assertShape($attributes);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($learner, $attributes, $replaceActive, $retrySource, $now): PracticeSession {
                    /** @var PracticeSession|null $active */
                    $active = PracticeSession::query()->where('active_learner_id', $learner->id)->lockForUpdate()->first();

                    if ($active !== null) {
                        if ($active->expires_at->lte($now)) {
                            $this->finish($active, FinishReason::Expired, $now, $learner);
                        } elseif ($replaceActive) {
                            $this->finish($active, FinishReason::Replaced, $now, $learner);
                        } else {
                            throw PracticeException::sessionActive($this->activeDetails($active));
                        }
                    }

                    $session = PracticeSession::query()->create($attributes);

                    DB::afterCommit(fn () => $this->events->record(ProductEventName::PracticeSessionStarted, [
                        'session_id' => $session->id,
                        'mode' => $session->mode->value,
                        'course_id' => $session->course_id,
                        'lesson_id' => $session->lesson_id,
                        'source' => $session->source,
                        'total' => $session->total,
                        'retry' => $retrySource !== null,
                    ], learner: $learner));

                    return $session;
                });
            } catch (UniqueConstraintViolationException) {
                // Two tabs at once: the other one has just opened a session for this learner.
                $winner = $this->activeOf($learner);
                if ($winner !== null && $winner->mode === $mode && $winner->course_id === $course->id && $winner->lesson_id === $lesson?->id) {
                    return $winner;
                }
                if ($winner !== null && ! $replaceActive) {
                    throw PracticeException::sessionActive($this->activeDetails($winner));
                }
                // The winner has ended already, or the learner asked to replace whatever is active: try once more.
            }
        }

        throw new LogicException('Could not open a practice session: the active one keeps changing under us.');
    }

    /**
     * The learner's OWN session by id (somebody else's looks like a missing one). A session past `expires_at` that the
     * scheduler has not closed yet is closed here (`expired`). Needs access to the practice.
     *
     * @throws PracticeException practice_unavailable | practice_pro_required | practice_session_not_found
     */
    public function find(Learner $learner, int $id, ?CarbonImmutable $now = null): PracticeSession
    {
        $this->access->assertCanPractice($learner);

        /** @var PracticeSession|null $session */
        $session = PracticeSession::query()->where('id', $id)->where('learner_id', $learner->id)->first();
        if ($session === null) {
            throw PracticeException::sessionNotFound();
        }

        return $this->expireIfDue($session, $learner, $now ?? CarbonImmutable::now());
    }

    /**
     * The learner's active session, if any (the hub shows "continue"); one past its end is closed first and not returned.
     */
    public function activeFor(Learner $learner, ?CarbonImmutable $now = null): ?PracticeSession
    {
        $session = $this->activeOf($learner);
        if ($session === null) {
            return null;
        }

        $session = $this->expireIfDue($session, $learner, $now ?? CarbonImmutable::now());

        return $session->isActive() ? $session : null;
    }

    /**
     * What the hub and the 409 `practice_session_active` tell about an active session.
     *
     * @return array{id: int, mode: string, course: string, lesson_id: int|null, progress: array{done: int, total: int}}
     */
    public function activeSummary(PracticeSession $session): array
    {
        $snapshot = Snapshot::fromArray($session->snapshot);

        return [
            'id' => $session->id,
            'mode' => $session->mode->value,
            'course' => $snapshot->courseSlug,
            'lesson_id' => $session->lesson_id,
            'progress' => ['done' => $session->answered, 'total' => $session->total],
        ];
    }

    /**
     * The session as the client may see it: the current element through the `project()` of its mode, or - once the
     * session has ended - the summary. No query.
     */
    public function view(PracticeSession $session, Learner $learner, ?CarbonImmutable $now = null): SessionView
    {
        $snapshot = Snapshot::fromArray($session->snapshot);
        $handler = $this->modes->for($session->mode);

        $current = null;
        $summary = null;
        if ($session->isActive()) {
            $element = $snapshot->at((int) ($session->state['current'] ?? 0));
            if ($element !== null) {
                $current = $handler->project($element, $session->locale, $this->elementState($session, $element));
            }
        } else {
            $summary = $this->summary($session, $snapshot, $handler);
        }

        return new SessionView(
            id: $session->id,
            status: $session->status->value,
            mode: $session->mode->value,
            source: $session->source,
            retryOf: $session->retry_of_session_id,
            course: ['slug' => $snapshot->courseSlug, 'title' => $snapshot->courseTitleFor($session->locale)],
            lesson: $session->lesson_id === null ? null : [
                'id' => $session->lesson_id,
                'stage' => $snapshot->lessonStage,
                'number' => $snapshot->lessonNumber,
                'title' => $snapshot->lessonTitle,
            ],
            done: $session->answered,
            total: $session->total,
            correctFirst: $session->correct_first,
            hints: $session->hints,
            mismatches: $session->mismatches,
            expiresAt: $session->expires_at,
            serverNow: $now ?? CarbonImmutable::now(),
            current: $current,
            summary: $summary,
        );
    }

    /**
     * Takes an answer (see the class docblock for the order of the checks). The session may be given by id: nothing is read
     * before the lock (one read of the row, not two).
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException practice_unavailable | practice_pro_required | practice_session_not_found | practice_session_closed |
     *                           unknown_element_id | answer_already_accepted | element_not_current | invalid_answer_shape |
     *                           pair_already_matched
     */
    public function answer(
        PracticeSession|int $session,
        Learner $learner,
        string $requestId,
        string $elementId,
        array $answer,
        ?int $timeSpentMs,
        ?CarbonImmutable $now = null,
    ): AnswerOutcome {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($learner);
        $requestId = strtolower($requestId);
        $sessionId = $session instanceof PracticeSession ? $session->id : $session;

        $outcome = DB::transaction(function () use ($sessionId, $learner, $requestId, $elementId, $answer, $timeSpentMs, $now): AnswerOutcome|PracticeException {
            $locked = $this->lock($sessionId, $learner);
            if ($locked === null) {
                return PracticeException::sessionNotFound();
            }

            $prior = $this->attemptOf($locked, $requestId);
            if ($prior !== null) {
                return new AnswerOutcome($prior->result, $this->view($locked, $learner, $now));
            }

            $closed = $this->refuseEnded($locked, $learner, $now);
            if ($closed !== null) {
                return $closed;
            }

            return $this->accept($locked, $learner, $requestId, $elementId, $answer, $timeSpentMs, $now);
        });

        // An error raised AFTER the commit, so that the closing of an expired session is kept.
        if ($outcome instanceof PracticeException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Swaps the current element for a spare without a penalty (a dictation whose audio does not play, docs/16 §10.4): the
     * next candidate of the pool, or - when the pool has none - the element goes and the session gets shorter (never below one
     * element). Counted: `practice.solo.dictation.max_replacements` per session. A repeated `request_id` changes nothing.
     *
     * @throws PracticeException practice_session_not_found | practice_session_closed | replace_not_allowed (not_dictation | not_current |
     *                           limit) | unknown_element_id
     */
    public function replace(
        PracticeSession|int $session,
        Learner $learner,
        string $requestId,
        string $elementId,
        string $reason,
        ?CarbonImmutable $now = null,
    ): SessionView {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($learner);
        $requestId = strtolower($requestId);
        $sessionId = $session instanceof PracticeSession ? $session->id : $session;

        $outcome = DB::transaction(function () use ($sessionId, $learner, $requestId, $elementId, $reason, $now): SessionView|PracticeException {
            $locked = $this->lock($sessionId, $learner);
            if ($locked === null) {
                return PracticeException::sessionNotFound();
            }
            if ($this->attemptOf($locked, $requestId) !== null) {
                return $this->view($locked, $learner, $now);
            }

            $closed = $this->refuseEnded($locked, $learner, $now);
            if ($closed !== null) {
                return $closed;
            }

            return $this->swap($locked, $learner, $requestId, $elementId, $reason, $now);
        });

        if ($outcome instanceof PracticeException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * The scheduler (`practice:close-expired`, every 15 minutes): every active session past its `expires_at` becomes
     * finished / `expired` and lets go of `active_learner_id`. One conditional UPDATE per session, no lock taken in advance - a
     * session that a request closed in the meantime is skipped and raises no second event.
     *
     * @return int sessions closed by this run
     */
    public function closeExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;

        PracticeSession::query()
            ->select(['id', 'learner_id', 'mode', 'total', 'answered', 'correct_first', 'hints', 'mismatches', 'started_at'])
            ->with('learner')
            ->where('status', SessionStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->lazyById(200)
            ->each(function (PracticeSession $session) use ($now, &$closed): void {
                if ($this->claimExpired($session->id, $now)) {
                    $closed++;
                    $session->forceFill(['finished_at' => $now]);
                    $this->recordFinished($session, FinishReason::Expired, $session->learner);
                }
            });

        return $closed;
    }

    /**
     * Whether the finished session has anything to repeat: words (pairs: at least a round of them) or tasks it got wrong.
     */
    public function retryAvailable(PracticeSession $session): bool
    {
        $material = $this->retryMaterial($session);

        if ($session->mode === PracticeMode::Pairs) {
            return count($material->wordIds) >= max(1, (int) config('practice.solo.pairs.min_per_round'));
        }

        return count($material->wordIds) + count($material->itemIds) >= 1;
    }

    /**
     * The state of a new session (docs/16 §6.2).
     *
     * @return array<string, mixed>
     */
    private static function initialState(): array
    {
        return ['current' => 0, 'elements' => [], 'pairs' => [], 'replacements' => 0, 'replaced' => []];
    }

    /**
     * The `CHECK`s of `practice_sessions` that only PostgreSQL enforces (docs/16 §2.9): the same on every driver.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function assertShape(array $attributes): void
    {
        $active = ($attributes['status'] ?? null) === SessionStatus::Active;
        $owner = $attributes['active_learner_id'] ?? null;

        if ($active !== ($owner !== null) || ($owner !== null && $owner !== $attributes['learner_id'])) {
            throw new LogicException('A practice session is active exactly when it has active_learner_id = learner_id.');
        }
    }

    /**
     * The snapshot; a mode that cannot be played (or that has too little material) is a 422 with the numbers - and the event.
     * For a retry the same lack is `retry_not_available`.
     */
    private function buildSnapshot(PracticeMode $mode, MaterialScope $scope, Learner $learner, LocaleSet $locales, bool $retry): Snapshot
    {
        $handler = $this->modes->for($mode);

        try {
            $availability = $handler->availability($scope, $learner, $locales);
            if (! $availability->isAvailable) {
                throw PracticeException::notEnoughMaterial($mode, $availability, $scope->course->slug, $scope->lesson?->id);
            }

            $size = $mode === PracticeMode::Pairs
                ? max(1, (int) config('practice.solo.pairs.rounds'))
                : max(1, (int) config('practice.solo.items.size'));

            return $this->snapshots->build($mode, $scope, $learner, $locales, $size, strictSize: false);
        } catch (PracticeException $e) {
            if ($e->errorCode !== PracticeException::NOT_ENOUGH_MATERIAL) {
                throw $e;
            }
            if ($retry) {
                throw PracticeException::retryNotAvailable();
            }

            $this->events->record(ProductEventName::PracticeNotEnoughMaterial, [
                'mode' => $mode->value,
                'course_id' => $scope->course->id,
                'lesson_id' => $scope->lesson?->id,
                'available' => $e->details['available'] ?? 0,
                'required' => $e->details['required'] ?? 0,
                'reason' => $e->details['reason'] ?? null,
                'battle' => false,
            ], learner: $learner);

            throw $e;
        }
    }

    /**
     * The finished session whose mistakes are repeated: the learner's own (anything else is "not found"), and ended.
     *
     * @throws PracticeException practice_session_not_found | retry_not_available
     */
    private function retrySource(Learner $learner, int $id, CarbonImmutable $now): PracticeSession
    {
        /** @var PracticeSession|null $source */
        $source = PracticeSession::query()->where('id', $id)->where('learner_id', $learner->id)->first();
        if ($source === null) {
            throw PracticeException::sessionNotFound();
        }

        $source = $this->expireIfDue($source, $learner, $now);
        if ($source->isActive() || ! $this->retryAvailable($source)) {
            throw PracticeException::retryNotAvailable();
        }

        return $source;
    }

    /**
     * What the learner got wrong in a session, as ids of words and tasks (docs/16 §6.1): the elements with a verdict that is not
     * "correct"; for a round of pairs the words of `wrong_left`. The keys it reads from the snapshot: `item_id`, `word_id` of
     * an element, `left[].id` / `left[].word_id` of a round.
     */
    private function retryMaterial(PracticeSession $session): RetryMaterial
    {
        $snapshot = Snapshot::fromArray($session->snapshot);

        $wordIds = [];
        $itemIds = [];
        foreach ($snapshot->elements as $element) {
            $state = $this->storedState($session, $element);
            if (! $state->isMistake()) {
                continue;
            }

            if ($element->isPairsRound()) {
                $wrong = (array) ($state->extra['wrong_left'] ?? []);
                foreach ((array) $element->get('left', []) as $left) {
                    if (is_array($left) && in_array($left['id'] ?? null, $wrong, true) && isset($left['word_id'])) {
                        $wordIds[] = (int) $left['word_id'];
                    }
                }

                continue;
            }

            if (isset($element->data['item_id'])) {
                $itemIds[] = (int) $element->data['item_id'];
            }
            if (isset($element->data['word_id'])) {
                $wordIds[] = (int) $element->data['word_id'];
            }
        }

        return new RetryMaterial(array_values(array_unique($wordIds)), array_values(array_unique($itemIds)));
    }

    /** @return array{session_id: int, mode: string, course: string, lesson_id: int|null, progress: array{done: int, total: int}} */
    private function activeDetails(PracticeSession $session): array
    {
        $summary = $this->activeSummary($session);

        return [
            'session_id' => $summary['id'],
            'mode' => $summary['mode'],
            'course' => $summary['course'],
            'lesson_id' => $summary['lesson_id'],
            'progress' => $summary['progress'],
        ];
    }

    private function activeOf(Learner $learner): ?PracticeSession
    {
        /** @var PracticeSession|null $session */
        $session = PracticeSession::query()->where('active_learner_id', $learner->id)->first();

        return $session;
    }

    private function lock(int $id, Learner $learner): ?PracticeSession
    {
        /** @var PracticeSession|null $session */
        $session = PracticeSession::query()->where('id', $id)->where('learner_id', $learner->id)->lockForUpdate()->first();

        return $session;
    }

    private function attemptOf(PracticeSession $session, string $requestId): ?PracticeAttempt
    {
        /** @var PracticeAttempt|null $attempt */
        $attempt = PracticeAttempt::query()->where('session_id', $session->id)->where('request_id', $requestId)->first();

        return $attempt;
    }

    /**
     * An ended session answers 410; one that is past its time is closed (kept: the caller returns the error after the commit).
     * Under the lock of the session.
     */
    private function refuseEnded(PracticeSession $locked, Learner $learner, CarbonImmutable $now): ?PracticeException
    {
        if (! $locked->isActive()) {
            return PracticeException::sessionClosed($locked->finish_reason);
        }
        if ($locked->expires_at->lte($now)) {
            $this->finish($locked, FinishReason::Expired, $now, $learner);

            return PracticeException::sessionClosed(FinishReason::Expired);
        }

        return null;
    }

    /**
     * Grades the answer and writes the attempt, the state and the counters; ends the session with the last element.
     *
     * @param  array<string, mixed>  $answer
     */
    private function accept(
        PracticeSession $session,
        Learner $learner,
        string $requestId,
        string $elementId,
        array $answer,
        ?int $timeSpentMs,
        CarbonImmutable $now,
    ): AnswerOutcome {
        $snapshot = Snapshot::fromArray($session->snapshot);
        $element = $snapshot->element($elementId) ?? throw PracticeException::unknownElementId();
        $elementState = $this->elementState($session, $element);

        if ($elementState->answered) {
            throw PracticeException::answerAlreadyAccepted($elementState->verdict);
        }

        $state = $session->state;
        $index = (int) ($state['current'] ?? 0);
        $current = $snapshot->at($index);
        if ($current === null || $current->id !== $element->id) {
            throw PracticeException::elementNotCurrent($current?->id);
        }

        $result = $this->modes->for($session->mode)->grade($element, $answer, $elementState);

        $pair = $element->isPairsRound() ? Arr::only($result->normalizedAnswer, ['left_id', 'right_id']) : null;
        $body = ['verdict' => $result->verdict->value, 'first_attempt' => $result->firstAttempt, 'reveal' => $result->reveal, 'pair' => $pair];
        if ($element->isPairsRound()) {
            $body['round_done'] = $result->final;
        }
        $body = JsonbKeyOrder::object($body);

        PracticeAttempt::query()->create([
            'session_id' => $session->id,
            'learner_id' => $learner->id,
            'request_id' => $requestId,
            'element_id' => $element->id,
            'kind' => $element->isPairsRound() ? PracticeAttempt::KIND_PAIR : ($result->verdict === Verdict::Skipped ? PracticeAttempt::KIND_SKIP : PracticeAttempt::KIND_ANSWER),
            'payload' => $result->normalizedAnswer,
            'verdict' => $result->verdict,
            'is_first' => $result->firstAttempt,
            'result' => $body,
            'time_spent_ms' => $timeSpentMs,
            'accepted_at' => $now,
        ]);

        $answered = $session->answered;
        if ($result->extra !== null) {
            $state['pairs'][$element->id] = $result->extra;
        }
        if ($result->final) {
            $state['elements'][$element->id] = (new ElementState(
                answered: true,
                verdict: $result->verdict,
                first: $result->firstAttempt,
                hints: $result->verdict === Verdict::Skipped ? 1 : 0,
                answer: $result->normalizedAnswer,
                answeredAt: IsoTime::format($now),
            ))->toStored();
            $state['current'] = $index + 1;
            $answered++;
        }

        $updates = [
            'state' => $state,
            'answered' => $answered,
            'correct_first' => $session->correct_first + ($result->verdict === Verdict::Correct && $result->firstAttempt ? 1 : 0),
            'hints' => $session->hints + ($result->verdict === Verdict::Skipped ? 1 : 0),
            'mismatches' => $session->mismatches + ($element->isPairsRound() && $result->verdict === Verdict::Wrong ? 1 : 0),
        ];
        $completed = $result->final && $state['current'] >= $session->total;
        if ($completed) {
            $updates += ['status' => SessionStatus::Finished, 'finish_reason' => FinishReason::Completed, 'finished_at' => $now, 'active_learner_id' => null];
        }

        $session->forceFill($updates)->save();

        if ($completed) {
            DB::afterCommit(fn () => $this->recordFinished($session, FinishReason::Completed, $learner));
        }

        return new AnswerOutcome($body, $this->view($session, $learner, $now));
    }

    /**
     * The body of {@see replace()} under the lock.
     */
    private function swap(PracticeSession $session, Learner $learner, string $requestId, string $elementId, string $reason, CarbonImmutable $now): SessionView|PracticeException
    {
        $handler = $this->modes->for($session->mode);
        if ($session->mode !== PracticeMode::Dictation || ! $handler instanceof ReplaceableElements) {
            return PracticeException::replaceNotAllowed(PracticeException::REPLACE_NOT_DICTATION);
        }

        $snapshot = Snapshot::fromArray($session->snapshot);
        $index = $snapshot->indexOf($elementId);
        if ($index === null) {
            return PracticeException::unknownElementId();
        }

        $state = $session->state;
        if ($index !== (int) ($state['current'] ?? 0)) {
            return PracticeException::replaceNotAllowed(PracticeException::REPLACE_NOT_CURRENT);
        }

        $replacements = (int) ($state['replacements'] ?? 0);
        if ($replacements >= $this->maxReplacements()) {
            return PracticeException::replaceNotAllowed(PracticeException::REPLACE_LIMIT);
        }

        $old = $snapshot->elements[$index];
        $swap = $handler->replacement($old, $snapshot->pool, new LocaleSet($snapshot->locales));

        $elements = $snapshot->elements;
        if ($swap !== null) {
            [$new, $pool] = $swap;
            $elements[$index] = $new;
            $snapshot = $snapshot->withElements(array_values($elements))->withPool($pool);
            $state['replaced'][$old->id] = $new->id;
        } else {
            if (count($elements) <= 1) {
                return PracticeException::replaceNotAllowed(PracticeException::REPLACE_LIMIT); // a session keeps at least one element
            }
            array_splice($elements, $index, 1);
            $snapshot = $snapshot->withElements($elements);
            $state['replaced'][$old->id] = null;
        }
        $snapshot->assertFits();
        $state['replacements'] = $replacements + 1;

        PracticeAttempt::query()->create([
            'session_id' => $session->id,
            'learner_id' => $learner->id,
            'request_id' => $requestId,
            'element_id' => $old->id,
            'kind' => PracticeAttempt::KIND_REPLACE,
            'payload' => ['reason' => $reason],
            'verdict' => null,
            'is_first' => false,
            'result' => ['replaced_with' => $swap === null ? null : $swap[0]->id],
            'time_spent_ms' => null,
            'accepted_at' => $now,
        ]);

        $updates = ['snapshot' => $snapshot->toArray(), 'state' => $state, 'total' => $snapshot->count()];
        // The element that went was the last one still to be answered: nothing is left to play.
        $completed = $snapshot->count() <= (int) $state['current'];
        if ($completed) {
            $updates += ['status' => SessionStatus::Finished, 'finish_reason' => FinishReason::Completed, 'finished_at' => $now, 'active_learner_id' => null];
        }
        $session->forceFill($updates)->save();

        DB::afterCommit(function () use ($session, $old, $learner, $completed): void {
            $this->events->record(ProductEventName::PracticeAudioFailed, [
                'session_id' => $session->id,
                'source' => $old->get('source'),
                'reason' => 'client_report',
                'verified' => false,
            ], learner: $learner);

            if ($completed) {
                $this->recordFinished($session, FinishReason::Completed, $learner);
            }
        });

        return $this->view($session, $learner, $now);
    }

    /**
     * Finishes a session that the caller holds under the lock (the new state is saved; the event follows the commit).
     */
    private function finish(PracticeSession $session, FinishReason $reason, CarbonImmutable $now, ?Learner $learner): void
    {
        $session->forceFill([
            'status' => SessionStatus::Finished,
            'finish_reason' => $reason,
            'finished_at' => $now,
            'active_learner_id' => null,
        ])->save();

        DB::afterCommit(fn () => $this->recordFinished($session, $reason, $learner));
    }

    /**
     * A session that is past its `expires_at` but still `active` is closed by one conditional UPDATE, the way the scheduler
     * does it; the event goes only for the request that really closed it. The model is brought up to date either way.
     */
    private function expireIfDue(PracticeSession $session, ?Learner $learner, CarbonImmutable $now): PracticeSession
    {
        if (! $session->isExpiredAt($now)) {
            return $session;
        }

        if ($this->claimExpired($session->id, $now)) {
            $session->forceFill([
                'status' => SessionStatus::Finished,
                'finish_reason' => FinishReason::Expired,
                'finished_at' => $now,
                'active_learner_id' => null,
            ])->syncOriginal();
            $this->recordFinished($session, FinishReason::Expired, $learner);

            return $session;
        }

        return $session->refresh();
    }

    /** The conditional UPDATE that closes one expired session; true when this call is the one that did. */
    private function claimExpired(int $id, CarbonImmutable $now): bool
    {
        return PracticeSession::query()
            ->whereKey($id)
            ->where('status', SessionStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => SessionStatus::Finished->value,
                'finish_reason' => FinishReason::Expired->value,
                'finished_at' => $now,
                'active_learner_id' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    private function recordFinished(PracticeSession $session, FinishReason $reason, ?Learner $learner): void
    {
        $finishedAt = $session->finished_at ?? CarbonImmutable::now();

        $this->events->record(ProductEventName::PracticeSessionFinished, [
            'session_id' => $session->id,
            'mode' => $session->mode->value,
            'total' => $session->total,
            'answered' => $session->answered,
            'correct_first' => $session->correct_first,
            'hints' => $session->hints,
            'mismatches' => $session->mismatches,
            'reason' => $reason->value,
            'duration_ms' => max(0, ($finishedAt->getTimestamp() - $session->started_at->getTimestamp()) * 1000),
        ], learner: $learner);
    }

    /**
     * The state of an element for the handler: what is stored, and the URL of its audio and the swaps left for `project()`.
     */
    private function elementState(PracticeSession $session, SnapshotElement $element): ElementState
    {
        $audioPath = $element->get('audio_path');
        $audioUrl = is_string($audioPath) && $audioPath !== ''
            ? route('twa.practice.sessions.audio', ['session' => $session->id, 'element' => $element->id], false)
            : null;

        return $this->storedState($session, $element)->withContext(
            $audioUrl,
            max(0, $this->maxReplacements() - (int) ($session->state['replacements'] ?? 0)),
        );
    }

    /** The element's entry of `state.elements` and its `state.pairs` block. */
    private function storedState(PracticeSession $session, SnapshotElement $element): ElementState
    {
        $stored = $session->state['elements'][$element->id] ?? null;
        $extra = $session->state['pairs'][$element->id] ?? [];

        return ElementState::fromStored(is_array($stored) ? $stored : null, is_array($extra) ? $extra : []);
    }

    /**
     * The summary of a finished session (docs/16 §6.4).
     */
    private function summary(PracticeSession $session, Snapshot $snapshot, PracticeModeHandler $handler): SummaryView
    {
        $typos = 0;
        $mistakes = [];
        foreach ($snapshot->elements as $element) {
            $state = $this->storedState($session, $element);
            if ($state->verdict === Verdict::Typo) {
                $typos++;
            }
            if (! $state->isMistake()) {
                continue;
            }

            // A round of pairs is stored "correct" (its last match is), yet every word of its `wrong_left` is a mistake.
            $verdict = $element->isPairsRound() ? Verdict::Wrong : ($state->verdict ?? Verdict::Wrong);
            foreach ($handler->mistakeLabel($element, $state, $session->locale) as $row) {
                $mistakes[] = [
                    'element_id' => $element->id,
                    'kind' => $element->kind,
                    'label' => $row['label'],
                    'your_answer' => $row['your_answer'],
                    'verdict' => $verdict->value,
                ];
            }
        }

        $finishedAt = $session->finished_at ?? $session->started_at;

        return new SummaryView(
            total: $session->total,
            answered: $session->answered,
            correctFirst: $session->correct_first,
            typos: $typos,
            hints: $session->hints,
            mismatches: $session->mode === PracticeMode::Pairs ? $session->mismatches : null,
            durationMs: max(0, ($finishedAt->getTimestamp() - $session->started_at->getTimestamp()) * 1000),
            finishReason: ($session->finish_reason ?? FinishReason::Completed)->value,
            retryAvailable: $this->retryAvailable($session),
            mistakes: $mistakes,
        );
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('practice.solo.session_ttl_hours'));
    }

    private function maxReplacements(): int
    {
        return max(0, (int) config('practice.solo.dictation.max_replacements'));
    }
}
