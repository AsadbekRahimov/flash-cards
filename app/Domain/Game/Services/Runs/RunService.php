<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Game\DTO\HintOutcome;
use App\Domain\Game\DTO\RunAnswerOutcome;
use App\Domain\Game\DTO\RunSnapshot;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\DTO\RunView;
use App\Domain\Game\DTO\ScoreOutcome;
use App\Domain\Game\DTO\ScoringRules;
use App\Domain\Game\Enums\AttemptKind;
use App\Domain\Game\Enums\FreeSlot;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Domain\Game\Enums\TaskKind;
use App\Domain\Game\Enums\TicketRefundReason;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Services\GameCardSender;
use App\Domain\Game\Services\GameLimits;
use App\Domain\Game\Services\Tasks\PracticeTaskAdapter;
use App\Domain\Game\Services\Tasks\TaskRegistry;
use App\Domain\Game\Support\CityNames;
use App\Domain\Game\Support\RunScoring;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Support\IsoTime;
use App\Domain\Practice\Support\JsonbKeyOrder;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * The life of a run (docs/18 §12.2.1, §12.5): start, find, view, answer, hint, abandon, close the expired ones. Everything a run
 * decides is decided here on the server from the snapshot and the state; the client only shows.
 *
 * Locks (docs/18 §12.5.1): the start of a Free learner with a limit takes the learner's row FIRST (`GameLimits::lockForRun`, FOR NO KEY
 * UPDATE), then `game_runs` (FOR UPDATE of the active one), inserts, and - a run paid with a ticket - updates `game_profiles` and inserts
 * the ledger entry (`TicketService::spendForRun`): `learners` -> `game_runs` -> `game_profiles` -> `game_ticket_ledger`, the profile
 * AFTER the run as in the finish. Pro takes `game_runs` only. An answer / a hint takes the run's own row FOR UPDATE, then - a hint of
 * the bazaar only - the wallet, then inserts the attempt and updates the run. Nothing of the learning is locked; the SRS rule runs
 * AFTER the commit (`GameSrsAdjuster`). The last answer hands the run to {@see RunFinisher} inside the same transaction (the rewards,
 * the map, the day of the learner - docs/18 §12.5.3). A run paid with a ticket that is closed WITHOUT A SINGLE ANSWER - replaced,
 * left, run out - gets its ticket back in the transaction of the close, after the row of the run and before the ledger
 * (`TicketService::refundForRun`, docs/18 §12.7.3): every close goes through {@see close()} (the row locked) or {@see claimExpired()}
 * (the conditional UPDATE), and those two are where the refund is decided.
 *
 * The view of the run (`RunView`: the boosters of the inventory, the summary, the pair to share after a first win over a boss - which
 * makes the learner's invite code in `learners` when there is none) is built AFTER the commit of the answer, the hint and the booster
 * (docs/18 §12.5.1 rule 24): the transaction of an answer holds the run, the map, the wallet, the profile and the day, and a write of
 * `learners` under them inverts rule 18 (`learners` first) against a Free start and a grant of tickets - a deadlock whose victim, inside
 * a swallowed savepoint, would turn the COMMIT of the answer into a silent rollback with the hooks still running.
 *
 * Idempotency: `(run_id, request_id)` is unique in `game_run_attempts`; a repeat answers the stored `result` and the view of now.
 */
final class RunService
{
    /** The route of the clip of an element (docs/18 §12.8); the URL goes into the projection of `listen` and `dictation`. */
    public const AUDIO_ROUTE = 'twa.game.runs.audio';

    public function __construct(
        private readonly GameAccess $access,
        private readonly CourseCatalogService $catalog,
        private readonly PracticeMaterialService $material,
        private readonly LocaleResolver $locales,
        private readonly LearnerClock $clock,
        private readonly RunSnapshotBuilder $snapshots,
        private readonly TaskRegistry $tasks,
        private readonly WalletService $wallet,
        private readonly GameSrsAdjuster $srs,
        private readonly ProductEventRecorder $events,
        private readonly RunFinisher $finisher,
        private readonly RunEventRecorder $runEvents,
        private readonly GameLimits $limits,
        private readonly TicketService $tickets,
        private readonly BoosterService $boosters,
        private readonly ClassCaravanService $caravans,
        private readonly GameCardSender $cards,
    ) {}

    /**
     * Starts the run of a stop (docs/18 §12.3.1). Not inside an outer transaction: after a unique violation (two tabs) the winner is read
     * again. An unfinished run of ANOTHER stop and no `$replaceActive` -> 409 `game_run_active` BEFORE the snapshot is built; an unfinished
     * run of the SAME stop is returned as it is (a second tab, a double tap: the run that is on is the answer); with the flag the old one is
     * closed `abandoned` in the same transaction. Two tabs at once: the loser gets the winner's run when it is the same stop, else the 409.
     * The Free limit of the day (docs/18 §12.7.2) is read before the material (the courtesy check, after the active run: a run that is
     * on is the answer whatever the limit) and claimed under the lock in {@see open()}.
     *
     * @throws GameException game_unavailable | course_not_found | lesson_not_found | lesson_not_in_course | stop_locked |
     *                       game_not_enough_material | game_run_active | game_limit_reached
     */
    public function start(Learner $learner, string $courseSlug, int $lessonId, bool $replaceActive = false, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);

        $course = $this->catalog->findAvailable($courseSlug, $learner) ?? throw GameException::courseNotFound();
        try {
            $lesson = $this->material->lesson($course, $lessonId, $learner);
        } catch (PracticeException $e) {
            throw $e->errorCode === PracticeException::LESSON_NOT_IN_COURSE ? GameException::lessonNotInCourse() : GameException::lessonNotFound();
        }

        if (! $replaceActive) {
            $busy = $this->activeOf($learner);
            if ($busy !== null && $busy->expires_at->gt($now)) {
                // The same stop asked twice (a second tab, a double tap): the run that is on IS the answer; another stop is a 409.
                if ($busy->kind === RunKind::Stop && $busy->lesson_id === $lesson->id) {
                    return $busy;
                }

                throw GameException::runActive($this->activeDetails($busy));
            }
        }

        try {
            $this->limits->assertRunAllowed($learner, RunKind::Stop, $now, $replaceActive);
        } catch (GameException $e) {
            // The limit was read a moment after another tab started this very stop (the run it saw none of now holds the place): the
            // run that is on is the answer, whatever the limit says - one more read on this refusal only, never on the way to a start.
            $busy = $e->errorCode === GameException::LIMIT_REACHED && ! $replaceActive ? $this->activeOf($learner) : null;
            if ($busy !== null && $busy->kind === RunKind::Stop && $busy->lesson_id === $lesson->id && $busy->expires_at->gt($now)) {
                return $busy;
            }

            throw $e;
        }

        $locales = new LocaleSet([$this->locales->forLearner($learner)]);
        try {
            $snapshot = $this->snapshots->forStop($learner, $course, $lesson, $locales);
        } catch (GameException $e) {
            if ($e->errorCode === GameException::NOT_ENOUGH_MATERIAL) {
                $this->events->record(ProductEventName::GameNotEnoughMaterial, [
                    'kind' => RunKind::Stop->value, 'course_id' => $course->id, 'lesson_id' => $lesson->id, ...$e->details,
                ], learner: $learner);
            }

            throw $e;
        }

        return $this->open($learner, $snapshot, [
            'course_id' => $course->id,
            'stage_id' => $lesson->stage_id,
            'lesson_id' => $lesson->id,
        ], $replaceActive, $now);
    }

    /**
     * The courtesy check of the start of a boss (like the one of a stop in {@see start()}): an unfinished run of the SAME boss is the
     * answer (a second tab, a double tap) and is returned; any other live run is 409 `game_run_active` - unless `$replaceActive`.
     * Null: nothing stands in the way.
     *
     * @throws GameException game_run_active
     */
    public function busyForBoss(Learner $learner, int $stageId, bool $replaceActive, CarbonImmutable $now): ?GameRun
    {
        if ($replaceActive) {
            return null;
        }
        $busy = $this->activeOf($learner);
        if ($busy === null || ! $busy->expires_at->gt($now)) {
            return null;
        }
        if ($busy->kind === RunKind::Boss && $busy->stage_id === $stageId) {
            return $busy;
        }

        throw GameException::runActive($this->activeDetails($busy));
    }

    /**
     * The same courtesy check for the boss of a class (G2.9a): the learner's own unfinished fight with the boss of THIS caravan is the
     * answer; any other live run is 409 `game_run_active` - unless `$replaceActive`.
     *
     * @throws GameException game_run_active
     */
    public function busyForClassBoss(Learner $learner, string $classBossKey, bool $replaceActive, CarbonImmutable $now): ?GameRun
    {
        if ($replaceActive) {
            return null;
        }
        $busy = $this->activeOf($learner);
        if ($busy === null || ! $busy->expires_at->gt($now)) {
            return null;
        }
        if ($busy->kind === RunKind::ClassBoss && $busy->class_boss_key === $classBossKey) {
            return $busy;
        }

        throw GameException::runActive($this->activeDetails($busy));
    }

    /**
     * Opens a run from a built snapshot: the transaction of the start (docs/18 §12.5.1). The daily caravan (G2.3) and the bosses (G2.4)
     * build their snapshots and call this with their `daily_key` / `class_boss_key` in `$attributes`; what pays for the run
     * (`free_slot`) is decided HERE by the Free limits - a map run (`stop` / `boss`) of a Free learner takes the free run of the day or
     * a ticket, or is refused; the caller has made the courtesy check (`GameLimits::assertRunAllowed`) before building the snapshot.
     *
     * @param  array<string, mixed>  $attributes  `course_id` (required), `stage_id`, `lesson_id`, `class_caravan_id`, `daily_key`, `class_boss_key`
     *
     * @throws GameException game_run_active | game_daily_done | game_limit_reached
     */
    public function open(Learner $learner, RunSnapshot $snapshot, array $attributes, bool $replaceActive, CarbonImmutable $now): GameRun
    {
        $row = [
            'learner_id' => $learner->id,
            'active_learner_id' => $learner->id,
            'kind' => $snapshot->kind,
            'course_id' => $attributes['course_id'] ?? throw new LogicException('A run needs its course.'),
            'stage_id' => $attributes['stage_id'] ?? null,
            'lesson_id' => $attributes['lesson_id'] ?? null,
            // The caravan of the class the run joins (G2.9a, docs/18 §12.5.3 step 6): read once, without a lock, at the start; the
            // finish reads it again under its lock. A `class_boss` run brings its own.
            'class_caravan_id' => $attributes['class_caravan_id'] ?? $this->caravans->activeIdFor($learner, $now),
            'daily_key' => $attributes['daily_key'] ?? null,
            'class_boss_key' => $attributes['class_boss_key'] ?? null,
            'local_date' => $this->clock->localDate($learner, $now),
            'status' => RunStatus::Active,
            'finish_reason' => null,
            'free_slot' => null,
            'snapshot' => $snapshot->toArray(),
            'state' => RunState::initial($snapshot->flasksMax)->toArray(),
            'total' => $snapshot->count(),
            'answered' => 0,
            'correct' => 0,
            'typos' => 0,
            'wrong' => 0,
            'hints' => 0,
            'flasks' => $snapshot->flasksMax,
            'flasks_max' => $snapshot->flasksMax,
            'combo_best' => 0,
            'score' => 0,
            'stars' => null,
            'coins_awarded' => 0,
            'league_points' => 0,
            'srs_pulled' => 0,
            'locale' => $snapshot->locales[0] ?? $this->locales->forLearner($learner),
            'expires_at' => $now->addMinutes(max(1, (int) config('game.run.ttl_minutes', 30))),
            'started_at' => $now,
            'finished_at' => null,
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $outcome = DB::transaction(function () use ($learner, $row, $replaceActive, $now): GameRun|GameException {
                    // The Free limits take the learner's row FIRST (docs/18 §12.5.1 rule 18); Pro and the kinds without a limit take nothing.
                    $pending = $this->limits->lockForRun($learner, $row['kind'], $now);

                    /** @var GameRun|null $active */
                    $active = GameRun::query()->where('active_learner_id', $learner->id)->lockForUpdate()->first();
                    if ($active !== null) {
                        if ($active->expires_at->lte($now)) {
                            $this->close($active, RunFinishReason::Expired, $now, $learner, TicketRefundReason::Expired);
                        } elseif ($replaceActive) {
                            // "Once more" on the same stop is a fresh run; one closed without an answer gives its place and its ticket back.
                            $this->close($active, RunFinishReason::Abandoned, $now, $learner, TicketRefundReason::Replaced);
                        } elseif ($this->isSameRun($active, $row)) {
                            return $active; // two tabs at once: the other one has just started this very run
                        } else {
                            return GameException::runActive($this->activeDetails($active));
                        }
                    }

                    // The place of the day or a ticket, counted under the lock now that the active run is settled (a run closed
                    // without an answer has just given its place - and its ticket - back); the refusal goes out of the transaction and
                    // is thrown after the commit, so that its event is recorded.
                    $claim = $pending === null ? null : $this->limits->claimRun($pending);
                    if ($claim instanceof GameException) {
                        return $claim;
                    }
                    $row['free_slot'] = $claim?->freeSlot;

                    $run = GameRun::query()->create($row);
                    if ($claim?->spendsTicket()) {
                        $this->tickets->spendForRun($learner, $run, $now); // game_profiles after game_runs, the order of the finish
                    }

                    DB::afterCommit(fn () => $this->events->record(ProductEventName::GameRunStarted, [
                        'run_id' => $run->id,
                        'kind' => $run->kind->value,
                        'course_id' => $run->course_id,
                        'stage_id' => $run->stage_id,
                        'lesson_id' => $run->lesson_id,
                        'total' => $run->total,
                        'free_slot' => $run->free_slot?->value,
                        'scope_widened' => (bool) ($run->snapshot['scope_widened'] ?? false),
                    ], learner: $learner));

                    return $run;
                });
                if ($outcome instanceof GameException) {
                    throw $outcome;
                }

                return $outcome;
            } catch (UniqueConstraintViolationException $e) {
                // The daily caravan of the day exists already (its key is unique): the second start is told so.
                if (($row['daily_key'] ?? null) !== null && GameRun::query()->where('daily_key', $row['daily_key'])->exists()) {
                    throw GameException::dailyDone($this->clock->limitWindowUtc($learner, $now)->endUtc->toIso8601ZuluString('millisecond'));
                }

                // Two tabs at once: the other one has just started a run for this learner - the very same one is the answer (the fight
                // with the boss of a class included: the loser of that race gets the fight that is on, docs/18 §12.11, not "done").
                $winner = $this->activeOf($learner);
                if ($winner !== null && $this->isSameRun($winner, $row)) {
                    return $winner;
                }
                if (($row['class_boss_key'] ?? null) !== null && GameRun::query()->where('class_boss_key', $row['class_boss_key'])->exists()) {
                    throw GameException::classBossDone(); // a fight that holds the key and is over: the member's one attempt is spent
                }
                if ($winner !== null && ! $replaceActive) {
                    throw GameException::runActive($this->activeDetails($winner));
                }
                // The winner has ended already, or the learner asked to replace whatever is active: try once more.
            }
        }

        throw new LogicException('Could not start a run: the active one keeps changing under us.');
    }

    /**
     * The learner's OWN run by id (somebody else's looks like a missing one). A run past `expires_at` that the scheduler has not closed
     * yet is closed here (`expired`).
     *
     * @throws GameException game_unavailable | game_run_not_found
     */
    public function find(Learner $learner, int $id, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);

        /** @var GameRun|null $run */
        $run = GameRun::query()->where('id', $id)->where('learner_id', $learner->id)->first();
        if ($run === null) {
            throw GameException::runNotFound();
        }

        return $this->expireIfDue($run, $learner, $now);
    }

    /** The learner's active run, if any (closed first when it is past its time). */
    public function activeFor(Learner $learner, ?CarbonImmutable $now = null): ?GameRun
    {
        $run = $this->activeOf($learner);

        return $run === null ? null : $this->expireIfDue($run, $learner, $now ?? CarbonImmutable::now());
    }

    /** What the learner sees of the run (docs/18 §12.8). */
    public function view(GameRun $run, Learner $learner, ?CarbonImmutable $now = null): RunView
    {
        $now ??= CarbonImmutable::now();
        $snapshot = RunSnapshot::fromArray($run->snapshot);
        $state = RunState::fromArray($run->state);
        $locale = $run->locale;

        $current = null;
        $hintAvailable = false;
        if ($run->isActive()) {
            $element = $snapshot->at($state->current);
            if ($element !== null) {
                $event = $snapshot->eventAt($state->current);
                $current = [
                    'element' => $this->withAudioUrl($run, $element, $this->tasks->forElement($element)->project($element, $locale, $state)),
                    'event' => $event?->value,
                ];
                $hintAvailable = $event === RunEvent::Bazaar && ! $state->isHinted($element->id) && $this->tasks->forElement($element)->hint($element, $state) !== null;
            }
        }

        return new RunView(
            id: $run->id,
            kind: $run->kind,
            status: $run->status,
            finishReason: $run->finish_reason,
            courseSlug: $snapshot->courseSlug === '' ? null : $snapshot->courseSlug,
            lessonId: $run->lesson_id,
            stageId: $run->kind === RunKind::Boss ? $run->stage_id : null,
            classCaravanId: $run->kind === RunKind::ClassBoss ? $run->class_caravan_id : null,
            city: $snapshot->stageNumber === null ? null : CityNames::name($snapshot->stageNumber, $locale),
            stageNumber: $snapshot->stageNumber,
            lessonNumber: $snapshot->lessonNumber,
            lessonTitle: RunSnapshot::titleFor($snapshot->lessonTitles, $locale),
            scopeWidened: $snapshot->scopeWidened,
            total: $run->total,
            position: min($state->current + 1, $run->total),
            flasks: $run->flasks,
            flasksMax: $run->flasks_max,
            combo: $state->combo,
            score: $run->score,
            current: $current,
            hint: ['price' => $this->hintPrice(), 'available' => $hintAvailable],
            boosters: ['available' => $run->isActive() ? $this->boosters->available($learner->id) : [], 'used' => $state->boostersUsed],
            summary: $run->isActive() ? null : $this->summary($run, $snapshot, $state, $learner),
            serverNow: $now->toIso8601ZuluString('millisecond'),
        );
    }

    /**
     * Accepts an answer to the current element (docs/18 §12.5.2): grades, scores, writes the attempt and the state, closes the run with
     * the last element (`completed`) or the last flask (`failed`). The SRS rule and the events follow the commit.
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws GameException game_unavailable | game_run_not_found | game_run_closed | request_id_reused | element_not_current |
     *                       answer_already_accepted | unknown_element_id | invalid_answer_shape | unknown_option_id | pair_already_matched
     */
    public function answer(GameRun|int $run, Learner $learner, string $requestId, string $elementId, array $answer, ?int $timeSpentMs = null, ?CarbonImmutable $now = null): RunAnswerOutcome
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);
        $requestId = strtolower($requestId);
        $runId = $run instanceof GameRun ? $run->id : $run;

        /** @var array{0: array<string, mixed>, 1: GameRun}|GameException $outcome */
        $outcome = DB::transaction(function () use ($runId, $learner, $requestId, $elementId, $answer, $timeSpentMs, $now): array|GameException {
            $locked = $this->lock($runId, $learner);
            if ($locked === null) {
                return GameException::runNotFound();
            }

            $prior = $this->attemptOf($locked, $requestId);
            if ($prior !== null) {
                return $prior->kind === AttemptKind::Hint || $prior->kind === AttemptKind::Booster
                    ? GameException::requestIdReused()
                    : [$prior->result, $locked];
            }

            $closed = $this->refuseClosed($locked, $learner, $now);
            if ($closed !== null) {
                return $closed;
            }

            return [$this->accept($locked, $learner, $requestId, $elementId, $answer, $timeSpentMs, $now), $locked];
        });

        // An error raised AFTER the commit, so that the closing of an expired run is kept.
        if ($outcome instanceof GameException) {
            throw $outcome;
        }
        [$body, $run] = $outcome;

        // The view AFTER the commit (docs/18 §12.5.1 rule 24): it reads the inventory and, for a first win over a boss, makes the
        // learner's invite code (`learners`) - nothing of that belongs under the locks of the run, the map, the wallet, the profile and the day.
        return new RunAnswerOutcome($body, $this->view($run, $learner, $now));
    }

    /**
     * The hint of the bazaar on the current element (docs/18 §12.5.2): the element carries the `bazaar` event, is not answered and has a
     * hint; the coins are taken under the lock of the run (`game_runs` -> `game_wallets`), once per element (`run:{id}:hint:{element}`). A
     * second request on the same element, with any `request_id`, returns the same hint and pays nothing.
     *
     * @throws GameException game_unavailable | game_run_not_found | game_run_closed | request_id_reused | element_not_current |
     *                       answer_already_accepted | hint_not_available | coins_insufficient
     */
    public function hint(GameRun|int $run, Learner $learner, string $requestId, string $elementId, ?CarbonImmutable $now = null): HintOutcome
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);
        $requestId = strtolower($requestId);
        $runId = $run instanceof GameRun ? $run->id : $run;

        /** @var array{0: array<string, mixed>, 1: GameRun}|GameException $outcome */
        $outcome = DB::transaction(function () use ($runId, $learner, $requestId, $elementId, $now): array|GameException {
            $locked = $this->lock($runId, $learner);
            if ($locked === null) {
                return GameException::runNotFound();
            }

            $prior = $this->attemptOf($locked, $requestId);
            if ($prior !== null) {
                return $prior->kind === AttemptKind::Hint
                    ? [$prior->result, $locked]
                    : GameException::requestIdReused();
            }

            $closed = $this->refuseClosed($locked, $learner, $now);
            if ($closed !== null) {
                return $closed;
            }

            $body = $this->giveHint($locked, $learner, $requestId, $elementId, $now);

            return $body instanceof GameException ? $body : [$body, $locked];
        });

        if ($outcome instanceof GameException) {
            throw $outcome;
        }
        [$body, $run] = $outcome;

        return new HintOutcome($body, $this->view($run, $learner, $now)); // the view after the commit (rule 24)
    }

    /**
     * A booster of the learner's inventory for the run (docs/18 §12.5.2, §12.8; {@see BoosterService}): under the lock of the run, once per
     * `request_id` (a repeat returns the run of now and spends nothing). The refusals are returned out of the transaction and thrown
     * after the commit, so that the closing of an expired run and the event `game.limit_reached` are kept.
     *
     * @throws GameException game_unavailable | game_run_not_found | game_run_closed | request_id_reused | item_not_found | unknown_element_id |
     *                       element_not_current | answer_already_accepted | hint_not_available | booster_already_used | booster_not_applicable |
     *                       booster_not_owned | booster_limit_reached
     */
    public function booster(GameRun|int $run, Learner $learner, string $requestId, string $itemKey, ?string $elementId = null, ?CarbonImmutable $now = null): RunView
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);
        $requestId = strtolower($requestId);
        $runId = $run instanceof GameRun ? $run->id : $run;

        $outcome = DB::transaction(function () use ($runId, $learner, $requestId, $itemKey, $elementId, $now): GameRun|GameException {
            $locked = $this->lock($runId, $learner);
            if ($locked === null) {
                return GameException::runNotFound();
            }

            $prior = $this->attemptOf($locked, $requestId);
            if ($prior !== null) {
                return $prior->kind === AttemptKind::Booster ? $locked : GameException::requestIdReused();
            }

            $closed = $this->refuseClosed($locked, $learner, $now);
            if ($closed !== null) {
                return $closed;
            }

            try {
                $this->boosters->use($locked, $learner, $requestId, $itemKey, $elementId, $now);
            } catch (GameException $e) {
                return $e;
            }

            return $locked;
        });

        if ($outcome instanceof GameException) {
            throw $outcome;
        }

        return $this->view($outcome, $learner, $now); // the view after the commit (rule 24)
    }

    /**
     * The learner leaves the run: `finished / abandoned`, nothing is paid (docs/18 §12.2.1); left without a single answer, a run paid
     * with a ticket gives the ticket back (docs/18 §12.7.3). A run that is closed already stays as it is.
     *
     * @throws GameException game_unavailable | game_run_not_found
     */
    public function abandon(GameRun|int $run, Learner $learner, ?CarbonImmutable $now = null): GameRun
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertAvailable($learner);
        $runId = $run instanceof GameRun ? $run->id : $run;

        $outcome = DB::transaction(function () use ($runId, $learner, $now): GameRun|GameException {
            $locked = $this->lock($runId, $learner);
            if ($locked === null) {
                return GameException::runNotFound();
            }
            if ($locked->isActive()) {
                $locked->expires_at->lte($now)
                    ? $this->close($locked, RunFinishReason::Expired, $now, $learner, TicketRefundReason::Expired)
                    : $this->close($locked, RunFinishReason::Abandoned, $now, $learner, TicketRefundReason::Exited);
            }

            return $locked;
        });

        if ($outcome instanceof GameException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * The scheduler (`game:close-expired`, every 15 minutes): every active run past its `expires_at` becomes `finished / expired` and lets
     * go of `active_learner_id` - one conditional UPDATE per run, no lock taken in advance; a run that a request closed in the meantime is
     * skipped and raises no second event. An expired run pays nothing (docs/18 §12.5.1 rule 23); one paid with a ticket and left
     * without a single answer gets the ticket back, in a transaction with its close ({@see claimExpired()}).
     *
     * @return int runs closed by this call
     */
    public function closeExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $closed = 0;

        GameRun::query()
            ->with('learner')
            ->where('status', RunStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->lazyById(200)
            ->each(function (GameRun $run) use ($now, &$closed): void {
                if ($this->claimExpired($run, $now, $run->learner)) {
                    $closed++;
                    $run->forceFill(['status' => RunStatus::Finished, 'finish_reason' => RunFinishReason::Expired, 'finished_at' => $now, 'active_learner_id' => null]);
                    $this->recordFinished($run, $run->learner);
                }
            });

        return $closed;
    }

    // ------------------------------------------------------------------ under the lock

    /**
     * The body of {@see answer()} under the lock of the run: the stored `result` of the attempt. The run leaves saved; its view is the
     * caller's, after the commit.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private function accept(GameRun $run, Learner $learner, string $requestId, string $elementId, array $answer, ?int $timeSpentMs, CarbonImmutable $now): array
    {
        $snapshot = RunSnapshot::fromArray($run->snapshot);
        $state = RunState::fromArray($run->state);
        $element = $snapshot->element($elementId) ?? throw GameException::unknownElementId();

        $current = $snapshot->at($state->current);
        if ($current === null || $current->id !== $element->id) {
            throw GameException::elementNotCurrent((string) $current?->id);
        }
        if ($state->isAnswered($element->id)) {
            throw GameException::answerAlreadyAccepted((string) $state->verdictOf($element->id)?->value);
        }

        $handler = $this->tasks->forElement($element);
        $grade = $handler->grade($element, $answer, $state);

        if ($grade->extra !== null) {
            $state->pairs[$element->id] = $grade->extra;
        }

        // A finished round of pairs is one answer: correct only without a mismatch; its flask went at the first mismatch.
        $verdict = $grade->verdict;
        if ($element->isPairsRound() && $grade->final) {
            $verdict = ($handler instanceof PracticeTaskAdapter && $handler->isPairsMistake($element, $state)) ? Verdict::Wrong : Verdict::Correct;
        }
        $hinted = $state->isHinted($element->id);
        $chargeFlask = ! $state->hasLostFlask($element->id) && ! ($element->isPairsRound() && $grade->final);
        $oasis = $snapshot->eventAt($state->current) === RunEvent::Oasis;

        $score = RunScoring::apply($state, $verdict, $grade->final, $hinted, $chargeFlask, $oasis, $run->flasks_max, ScoringRules::fromConfig());

        $state->flasks = $score->flasks;
        $state->combo = $score->combo;
        if ($score->oasisApplied) {
            $state->oasisUsed = true;
        }
        if ($score->secondChanceUsed) {
            $state->secondChanceLeft = max(0, $state->secondChanceLeft - 1);
        }
        if ($score->flaskLost) {
            $state->markFlaskLost($element->id);
        }
        // An answer after a hint is a mistake for the SRS whatever its verdict (docs/18 §12.4: the learner did not know).
        $wordIds = $grade->srsErrorWordIds;
        if ($hinted && $grade->final) {
            $wordIds = array_values(array_unique([...$wordIds, ...$handler->wordIds($element)]));
        }
        $state->rememberMistakes($wordIds);

        $pair = $element->isPairsRound() ? ['left_id' => $grade->normalizedAnswer['left_id'] ?? null, 'right_id' => $grade->normalizedAnswer['right_id'] ?? null] : null;
        $body = [
            'verdict' => $grade->verdict->value,
            'points' => $score->points,
            'reveal' => $grade->reveal,
            'flask_lost' => $score->flaskLost,
            'combo' => $score->combo,
            'event_applied' => $score->oasisApplied ? RunEvent::Oasis->value : null,
        ];
        if ($element->isPairsRound()) {
            $body['pair'] = $pair;
            $body['round_done'] = $grade->final;
        }
        $body = JsonbKeyOrder::object($body);

        GameRunAttempt::query()->create([
            'run_id' => $run->id,
            'learner_id' => $learner->id,
            'request_id' => $requestId,
            'element_id' => $element->id,
            'kind' => $element->isPairsRound() ? AttemptKind::Pair : ($grade->verdict === Verdict::Skipped ? AttemptKind::Skip : AttemptKind::Answer),
            'payload' => $grade->normalizedAnswer,
            'verdict' => $grade->verdict,
            'is_first' => $grade->firstAttempt,
            'result' => $body,
            'time_spent_ms' => $timeSpentMs,
            'accepted_at' => $now,
        ]);

        $updates = [
            'flasks' => $score->flasks,
            'combo_best' => max($run->combo_best, $score->combo),
            'score' => $run->score + $score->points,
        ];
        if ($grade->final) {
            $state->markAnswered($element->id, $verdict, $grade->firstAttempt, $score->points, $state->hasLostFlask($element->id), $grade->normalizedAnswer, IsoTime::format($now));
            $state->current++;
            $updates['answered'] = $run->answered + 1;
            $updates += match ($score->counted) {
                ScoreOutcome::COUNTED_CORRECT => ['correct' => $run->correct + 1],
                ScoreOutcome::COUNTED_TYPO => ['typos' => $run->typos + 1],
                ScoreOutcome::COUNTED_HINT => ['hints' => $run->hints + 1],
                default => ['wrong' => $run->wrong + 1],
            };
        }
        $updates['state'] = $state->toArray();
        $run->forceFill($updates);

        // The last flask or the last element: the finish (docs/18 §12.5.3) saves the run - rewards, map and day in this very transaction.
        if ($score->failed) {
            $this->finisher->finish($run, RunFinishReason::Failed, $learner, $now);
        } elseif ($state->current >= $run->total) {
            $this->finisher->finish($run, RunFinishReason::Completed, $learner, $now);
        } else {
            $run->save();
        }

        if ($wordIds !== []) {
            DB::afterCommit(function () use ($run, $learner, $wordIds, $now): void {
                try {
                    $moved = $this->srs->pull($learner->id, $wordIds, $now);
                    if ($moved > 0) {
                        GameRun::query()->whereKey($run->id)->increment('srs_pulled', $moved);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $body;
    }

    /**
     * The body of {@see hint()} under the lock of the run: `{hint, coins: {balance}}`, or the refusal.
     *
     * @return array<string, mixed>|GameException
     */
    private function giveHint(GameRun $run, Learner $learner, string $requestId, string $elementId, CarbonImmutable $now): array|GameException
    {
        $snapshot = RunSnapshot::fromArray($run->snapshot);
        $state = RunState::fromArray($run->state);
        $element = $snapshot->element($elementId);
        if ($element === null) {
            return GameException::unknownElementId();
        }
        $current = $snapshot->at($state->current);
        if ($current === null || $current->id !== $element->id) {
            return GameException::elementNotCurrent((string) $current?->id);
        }
        if ($state->isAnswered($element->id)) {
            return GameException::answerAlreadyAccepted((string) $state->verdictOf($element->id)?->value);
        }

        $price = $this->hintPrice();
        $given = $state->hintOf($element->id);
        if ($given !== null) {
            // The element has its hint already (another request_id, or a reload): the same hint, nothing to pay.
            $body = JsonbKeyOrder::object(['hint' => $given, 'coins' => ['balance' => $this->wallet->balance($learner->id)]]);
            $this->journalHint($run, $learner, $requestId, $element, $body, $now);

            return $body;
        }

        if ($snapshot->eventAt($state->current) !== RunEvent::Bazaar) {
            return GameException::hintNotAvailable();
        }
        $hint = $this->tasks->forElement($element)->hint($element, $state);
        if ($hint === null) {
            return GameException::hintNotAvailable();
        }

        try {
            $debit = $this->wallet->debit($learner->id, $price, LedgerKind::BazaarHint, "run:{$run->id}:hint:{$element->id}", 'game_run', $run->id, ['element_id' => $element->id]);
        } catch (GameException $e) {
            return $e;
        }

        $state->markHinted($element->id, $hint);
        $run->forceFill(['state' => $state->toArray()])->save();

        $body = JsonbKeyOrder::object(['hint' => $hint, 'coins' => ['balance' => $debit->balance]]);
        $this->journalHint($run, $learner, $requestId, $element, $body, $now);

        if ($debit->applied) {
            DB::afterCommit(fn () => $this->events->record(ProductEventName::GameCoinsSpent, [
                'amount' => $price, 'kind' => LedgerKind::BazaarHint->value, 'ref_type' => 'game_run', 'ref_id' => $run->id, 'balance' => $debit->balance,
            ], learner: $learner));
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function journalHint(GameRun $run, Learner $learner, string $requestId, SnapshotElement $element, array $body, CarbonImmutable $now): void
    {
        GameRunAttempt::query()->create([
            'run_id' => $run->id,
            'learner_id' => $learner->id,
            'request_id' => $requestId,
            'element_id' => $element->id,
            'kind' => AttemptKind::Hint,
            'payload' => [],
            'verdict' => null,
            'is_first' => false,
            'result' => $body,
            'time_spent_ms' => null,
            'accepted_at' => $now,
        ]);
    }

    /**
     * Closes a run that pays nothing (`expired` / `abandoned`) under the lock of its row - the row is exact, so `answered` decides the
     * refund of a ticket here and now (docs/18 §12.7.3): `game_runs` -> `game_profiles` -> `game_ticket_ledger`, the order of the start
     * and of the finish. A fight with the boss of a class closed without a single answer lets go of its `class_boss_key` in the same
     * UPDATE (G2.13, docs/18 §12.11): it was no attempt, the member may fight.
     */
    private function close(GameRun $run, RunFinishReason $reason, CarbonImmutable $now, ?Learner $learner, TicketRefundReason $refund): void
    {
        $run->forceFill([
            'status' => RunStatus::Finished,
            'finish_reason' => $reason,
            'finished_at' => $now,
            'active_learner_id' => null,
            'class_boss_key' => self::keepsClassBossKey($run) ? $run->class_boss_key : null,
        ])->save();

        $this->tickets->refundForRun($run, $refund, $learner, $now); // nothing unless free_slot = ticket and no answer

        DB::afterCommit(fn () => $this->recordFinished($run, $learner));
    }

    /** A closed run answers 410; one past its time is closed (kept: the caller returns the error after the commit). Under the lock. */
    private function refuseClosed(GameRun $locked, Learner $learner, CarbonImmutable $now): ?GameException
    {
        if (! $locked->isActive()) {
            return GameException::runClosed((string) $locked->finish_reason?->value);
        }
        if ($locked->expires_at->lte($now)) {
            $this->close($locked, RunFinishReason::Expired, $now, $learner, TicketRefundReason::Expired);

            return GameException::runClosed(RunFinishReason::Expired->value);
        }

        return null;
    }

    // ------------------------------------------------------------------ reads

    private function lock(int $id, Learner $learner): ?GameRun
    {
        /** @var GameRun|null $run */
        $run = GameRun::query()->where('id', $id)->where('learner_id', $learner->id)->lockForUpdate()->first();

        return $run;
    }

    private function activeOf(Learner $learner): ?GameRun
    {
        /** @var GameRun|null $run */
        $run = GameRun::query()->where('active_learner_id', $learner->id)->first();

        return $run;
    }

    private function attemptOf(GameRun $run, string $requestId): ?GameRunAttempt
    {
        /** @var GameRunAttempt|null $attempt */
        $attempt = GameRunAttempt::query()->where('run_id', $run->id)->where('request_id', $requestId)->first();

        return $attempt;
    }

    /**
     * The active run is the very run the row describes (the same kind, stop, city and keys): a second tab gets it instead of a 409.
     *
     * @param  array<string, mixed>  $row
     */
    private function isSameRun(GameRun $active, array $row): bool
    {
        return $active->kind === $row['kind']
            && $active->lesson_id === ($row['lesson_id'] ?? null)
            && $active->stage_id === ($row['stage_id'] ?? null)
            && $active->daily_key === ($row['daily_key'] ?? null)
            && $active->class_boss_key === ($row['class_boss_key'] ?? null);
    }

    /** @return array{run_id: int, kind: string, progress: array<string, mixed>} */
    private function activeDetails(GameRun $run): array
    {
        return [
            'run_id' => $run->id,
            'kind' => $run->kind->value,
            'progress' => ['answered' => $run->answered, 'total' => $run->total, 'flasks' => $run->flasks, 'score' => $run->score, 'expires_at' => $run->expires_at->toIso8601ZuluString('millisecond')],
        ];
    }

    /** A run past its `expires_at` but still `active` is closed by one conditional UPDATE, the way the scheduler does it. */
    private function expireIfDue(GameRun $run, ?Learner $learner, CarbonImmutable $now): GameRun
    {
        if (! $run->isExpiredAt($now)) {
            return $run;
        }

        if ($this->claimExpired($run, $now, $learner)) {
            $run->forceFill(['status' => RunStatus::Finished, 'finish_reason' => RunFinishReason::Expired, 'finished_at' => $now, 'active_learner_id' => null])->syncOriginal();
            $this->recordFinished($run, $learner);

            return $run;
        }

        return $run->refresh();
    }

    /**
     * Closes one expired run by a conditional UPDATE, no lock taken in advance; true when this call is the one that did. `$run` was read
     * WITHOUT a lock, so its `answered` may be behind an answer that landed since (`free_slot` and `kind` never change, `answered` never
     * falls). A run whose close without an answer gives something back - the ticket that paid for it, the `class_boss_key` of a fight
     * with the boss of a class (G2.13) - and that was seen without an answer is closed by an UPDATE that checks `answered = 0` itself on
     * the row of now: when it matches, the run is closed, the key is let go of and the ticket comes back (in one transaction); when it
     * does not, either an answer landed (the ticket stays spent, the key stays - the plain UPDATE closes the run) or somebody else closed
     * the run (nothing to do). Never both an answer and a refund.
     */
    private function claimExpired(GameRun $run, CarbonImmutable $now, ?Learner $learner): bool
    {
        if ($run->answered > 0 || ($run->free_slot !== FreeSlot::Ticket && $run->kind !== RunKind::ClassBoss)) {
            return $this->expireRow($run->id, $now, unanswered: false);
        }

        return DB::transaction(function () use ($run, $now, $learner): bool {
            if ($this->expireRow($run->id, $now, unanswered: true)) {
                $run->forceFill(['class_boss_key' => null]); // the row has let go of it (a run of another kind had none)
                $this->tickets->refundForRun($run, TicketRefundReason::Expired, $learner, $now);

                return true;
            }

            return $this->expireRow($run->id, $now, unanswered: false);
        });
    }

    /**
     * The conditional UPDATE itself: `status = active AND expires_at <= now` (and `answered = 0` when `$unanswered` - then the row also lets
     * go of its `class_boss_key`, which only a fight with the boss of a class has); true for one row.
     */
    private function expireRow(int $id, CarbonImmutable $now, bool $unanswered): bool
    {
        return GameRun::query()
            ->whereKey($id)
            ->where('status', RunStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->when($unanswered, fn ($query) => $query->where('answered', 0))
            ->update([
                'status' => RunStatus::Finished->value,
                'finish_reason' => RunFinishReason::Expired->value,
                'finished_at' => $now,
                'active_learner_id' => null,
                'updated_at' => $now,
                ...($unanswered ? ['class_boss_key' => null] : []),
            ]) === 1;
    }

    /**
     * A fight with the boss of a class keeps its `class_boss_key` - the member's one attempt - only once it has an answer (G2.13, docs/18
     * §12.11); any other run has no key to speak of and keeps its NULL.
     */
    private static function keepsClassBossKey(GameRun $run): bool
    {
        return $run->kind !== RunKind::ClassBoss || $run->answered > 0;
    }

    /** `game.run_finished` of a run closed without a reward (`expired` / `abandoned`); a played run's event is RunFinisher's. */
    private function recordFinished(GameRun $run, ?Learner $learner): void
    {
        $this->runEvents->finished($run, $learner);
    }

    /**
     * The summary of a finished run (docs/18 §12.9): the stars, the score, what was earned, the mistakes with their labels.
     *
     * @return array<string, mixed>
     */
    private function summary(GameRun $run, RunSnapshot $snapshot, RunState $state, Learner $learner): array
    {
        $mistakes = [];
        foreach ($snapshot->elements as $element) {
            $entry = $state->entry($element->id);
            if ($entry === null || ($entry['status'] ?? null) !== RunState::STATUS_ANSWERED) {
                continue;
            }
            $handler = $this->tasks->forElement($element);
            $verdict = $state->verdictOf($element->id);
            $isMistake = $verdict !== null && $verdict !== Verdict::Correct && $verdict !== Verdict::Typo;
            if ($element->isPairsRound() && $handler instanceof PracticeTaskAdapter) {
                $isMistake = $handler->isPairsMistake($element, $state);
            }
            if (! $isMistake && ! (bool) ($entry['hinted'] ?? false)) {
                continue;
            }

            // `hinted` tells a row that is here for the hint alone (a right answer after a hint is a mistake for the SRS, docs/18 §12.4)
            // from a wrong or skipped one; `verdict` stays what the answer was.
            foreach ($handler->mistakeLabel($element, $state, $run->locale) as $row) {
                $mistakes[] = [
                    'element_id' => $element->id,
                    'kind' => $element->kind,
                    'label' => $row['label'],
                    'your_answer' => $row['your_answer'],
                    'verdict' => $element->isPairsRound() ? Verdict::Wrong->value : (string) $verdict?->value,
                    'hinted' => (bool) ($entry['hinted'] ?? false),
                ];
            }
        }

        // `streak_held` and `new_stars` are what the finish wrote into the state (RunFinisher step 1, G2.13): a run closed without a
        // finish (abandoned, expired) held no day and gained no star.
        return [
            'stars' => $run->stars,
            'score' => $run->score,
            'coins' => $run->coins_awarded,
            'league_points' => $run->league_points,
            'srs_pulled' => $run->srs_pulled,
            'mistakes' => $mistakes,
            'streak_held' => (bool) ($run->state['streak_held'] ?? false),
            'new_stars' => (int) ($run->state['new_stars'] ?? 0),
            'boss' => $this->bossSummary($run, $snapshot, $learner),
        ];
    }

    /**
     * `summary.boss` of the fight with a boss (docs/18 §12.8): the accuracy in whole per cent, whether the fight is won, whether it was
     * the first win over the city's boss (kept in the state by the finisher), and - for that first win only - `share {link, text, title}` of the
     * card of the city (the pair of the DM card, {@see GameCardSender::share()}; `null` without a link). A fight left unfinished (abandoned,
     * expired) is never won.
     * `null` for any other run.
     *
     * @return array{accuracy: int, won: bool, first: bool, share: array{link: string, text: string, title: string}|null}|null
     */
    private function bossSummary(GameRun $run, RunSnapshot $snapshot, Learner $learner): ?array
    {
        if ($run->kind !== RunKind::Boss && $run->kind !== RunKind::ClassBoss) {
            return null;
        }

        $won = $run->finish_reason === RunFinishReason::Completed && RunScoring::bossWon($run->correct, $run->total, (float) config('game.boss.required_accuracy', 0.8));
        $first = $run->kind === RunKind::Boss && (bool) ($run->state['boss_first'] ?? false);

        return [
            'accuracy' => RunScoring::bossAccuracy($run->correct, $run->total),
            'won' => $won,
            'first' => $first,
            'share' => $won && $first && $snapshot->stageNumber !== null ? $this->cards->share($learner, $snapshot->stageNumber) : null,
        ];
    }

    /**
     * The URL of the clip of a `listen` / `dictation` element (docs/18 §12.8: the audio route of the run, by the opaque id of the element).
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function withAudioUrl(GameRun $run, SnapshotElement $element, array $projection): array
    {
        if (($element->kind === TaskKind::Listen->value || $element->kind === TaskKind::Dictation->value) && is_string($element->get('audio_path'))) {
            $projection['audio_url'] = route(self::AUDIO_ROUTE, ['run' => $run->id, 'element' => $element->id], false);
        }

        return $projection;
    }

    private function hintPrice(): int
    {
        return max(0, (int) config('game.events.bazaar_hint_coins', 5));
    }
}
