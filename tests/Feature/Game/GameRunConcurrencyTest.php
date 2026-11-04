<?php

declare(strict_types=1);

use App\Domain\Game\Enums\FreeSlot;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Game\Services\GameCardSender;
use App\Domain\Game\Services\Runs\BossService;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\Achievement;
use App\Models\GameCityProgress;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameStopProgress;
use App\Models\GameTicketLedgerEntry;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\NotificationLog;
use App\Models\PracticeSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.5.4: the real races of a run. Several OS processes, each with its own PostgreSQL connection, hit the same learner / run at
 * the same instant. The `FOR UPDATE` of the run row, the unique `active_learner_id`, the unique `(run_id, request_id)` and the unique
 * `idempotency_key` of the ledger are what the assertions rely on, so this file runs only on pgsql with pcntl. Every worker sets a
 * lock timeout: a deadlock (40P01) or a wait that never ends (55P03) is a crash, never a hang. Data is committed (DatabaseTruncation).
 * The races of the Free limits and the tickets (`GameLimits`, `TicketService`, G2.8) are at the end - with the refund of the ticket of
 * a run closed without an answer (G2.8b): against its first answer, against a second close, against a spend and a grant.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * The harness lives in Tests\Support\Game\GameRace (shared with GameWalletConcurrencyTest since G2.5a); these are its names here.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, code?: string, details?: array<string, mixed>, error?: string}>
 */
function gameRace(array $tasks): array
{
    return GameRace::run($tasks);
}

/** The workers that crashed (not the ones the game refused). @param list<array<string, mixed>> $results @return list<string> */
function gameRaceCrashes(array $results): array
{
    return GameRace::crashes($results);
}

/** `ok` for a worker that succeeded, else its code. @param list<array<string, mixed>> $results @return list<string> */
function gameRaceOutcomes(array $results): array
{
    return GameRace::outcomes($results);
}

function gameRaceLearner(int $id): Learner
{
    return GameRace::learner($id);
}

/** @return list<array<string, mixed>> */
function gameRaceEvents(string $name): array
{
    return GameRace::events($name);
}

it('gives one learner one active run when two tabs start the same stop at once: both answer with the same run', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learnerId = $learner->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    $results = gameRace(array_fill(0, 2, fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId)->id));

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok'])
        ->and($results[0]['value'])->toBe($results[1]['value'])
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->count())->toBe(1)
        ->and(gameRaceEvents('game.run_started'))->toHaveCount(1);
});

it('accepts one of two answers to the same element from two tabs: the other is told the element is not current', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $learnerId = $learner->id;
    $runId = $run->id;
    $answers = [GameKit::rightAnswers($element)[0], GameKit::wrongAnswer($element)];

    $results = gameRace(array_map(
        fn (array $answer): Closure => fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $element['id'], $answer)->body['verdict'],
        $answers,
    ));

    $run->refresh();
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['element_not_current', 'ok'])
        ->and(GameRunAttempt::query()->where('run_id', $runId)->count())->toBe(1)
        ->and($run->answered)->toBe(1)
        ->and($run->state['current'])->toBe(1)
        ->and($run->correct + $run->wrong)->toBe(1);
});

it('journals one attempt for the same request_id sent twice at once and answers both with the same body', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $learnerId = $learner->id;
    $runId = $run->id;
    $requestId = (string) Str::uuid();
    $answer = GameKit::rightAnswers($element)[0];

    $results = gameRace(array_fill(0, 2, fn (): array => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), $requestId, $element['id'], $answer)->body));

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok'])
        ->and($results[0]['value'])->toBe($results[1]['value'])
        ->and(GameRunAttempt::query()->where('run_id', $runId)->count())->toBe(1)
        ->and($run->refresh()->answered)->toBe(1);
});

it('closes an expired run once when an answer and the scheduler meet: the answer is 410, the row is consistent, one finished event', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $later = CarbonImmutable::parse('2026-11-05 10:31:00');
    $learnerId = $learner->id;
    $runId = $run->id;

    $results = gameRace([
        fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0], null, $later)->body['verdict'],
        fn (): int => app(RunService::class)->closeExpired($later),
    ]);

    $run->refresh();
    expect(gameRaceCrashes($results))->toBe([])
        ->and($results[0]['ok'])->toBeFalse()
        ->and($results[0]['code'])->toBe('game_run_closed')
        ->and($results[0]['details'])->toBe(['finish_reason' => 'expired'])
        ->and($results[1]['ok'])->toBeTrue()
        ->and($run->finish_reason)->toBe(RunFinishReason::Expired)
        ->and($run->active_learner_id)->toBeNull()
        ->and($run->stars)->toBeNull()
        ->and(GameRunAttempt::query()->where('run_id', $runId)->count())->toBe(0)
        ->and(gameRaceEvents('game.run_finished'))->toHaveCount(1);
});

it('sells the hint of the bazaar once when two tabs buy it at the same instant: one ledger entry, the same hint to both', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameWallet::factory()->withBalance(9)->create(['learner_id' => $learner->id]);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => 0, 'type' => RunEvent::Bazaar->value]]]])->save();
    $element = GameKit::current($run);
    $learnerId = $learner->id;
    $runId = $run->id;

    $results = gameRace(array_fill(0, 2, fn (): array => app(RunService::class)->hint($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $element['id'])->body));

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok'])
        ->and($results[0]['value'])->toBe($results[1]['value'])
        ->and($results[0]['value']['coins'])->toBe(['balance' => 4])
        ->and(GameCoinLedgerEntry::query()->count())->toBe(1)
        ->and(GameWallet::query()->where('learner_id', $learnerId)->value('balance'))->toBe(4)
        ->and(GameRunAttempt::query()->where('run_id', $runId)->where('kind', 'hint')->count())->toBe(2)
        ->and(gameRaceEvents('game.coins_spent'))->toHaveCount(1);
});

it('finishes a run once when two tabs send the last answer at the same instant: one completed, the other told the run is closed', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    // Play to the last element.
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    expect($last)->not->toBeNull();
    $learnerId = $learner->id;
    $runId = $run->id;
    $answer = GameKit::rightAnswers($last)[0];

    $results = gameRace(array_fill(0, 2, fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $last['id'], $answer)->run->status->value));

    $run->refresh();
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['game_run_closed', 'ok'])
        ->and($run->finish_reason)->toBe(RunFinishReason::Completed)
        ->and($run->answered)->toBe($run->total)
        ->and($run->stars)->toBe(3)
        ->and(GameRunAttempt::query()->where('run_id', $runId)->count())->toBe($run->total)
        ->and(gameRaceEvents('game.run_finished'))->toHaveCount(1);
});

it('does not deadlock a start against the lock of the learner row the training picker takes', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learnerId = $learner->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    $results = gameRace([
        fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId)->id,
        function () use ($learnerId): int {
            return DB::transaction(function () use ($learnerId): int {
                Learner::query()->whereKey($learnerId)->lock('for no key update')->value('id');
                usleep(200_000);

                return 1;
            });
        },
    ]);

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok'])
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->count())->toBe(1);
});

// ---------------------------------------------------------------- the Free limits and the tickets (G2.8, docs/18 §12.7.2)

/** The tickets of a learner as the ledger and the profile see them: `[tickets, SUM(ledger), spends, refunds]`. @return array{0: int, 1: int, 2: int, 3: int} */
function gameRaceTickets(int $learnerId): array
{
    $ledger = GameTicketLedgerEntry::query()->where('learner_id', $learnerId);

    return [
        (int) GameProfile::query()->where('learner_id', $learnerId)->value('tickets'),
        (int) $ledger->clone()->sum('delta'),
        $ledger->clone()->where('source', 'spend')->count(),
        $ledger->clone()->where('source', 'refund')->count(),
    ];
}

/**
 * A Free learner (limit 1) with one ticket in the ledger, the free run of the day played, and a run paid with the ticket on - untouched.
 *
 * @return array{learnerId: int, slug: string, lessonId: int, runId: int}
 */
function gameRaceTicketRun(): array
{
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::stop(8);
    app(TicketService::class)->grantForStreak($learner, 7, '2026-11-05');
    $runs = app(RunService::class);
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    expect($run->free_slot)->toBe(FreeSlot::Ticket)->and(gameRaceTickets($learner->id))->toBe([0, 0, 1, 0]);

    return ['learnerId' => $learner->id, 'slug' => $course->slug, 'lessonId' => $lesson->id, 'runId' => $run->id];
}

it('spends one ticket per run when three tabs of a Free learner start at the same instant, and gives the ticket of a run replaced without an answer back', function (bool $replaceActive, int $runs, int $distinct, int $spends, int $refunds): void {
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::stop(8);
    app(TicketService::class)->grantForStreak($learner, 7, '2026-11-05');
    // The free run of the day is played: every further run of the day costs a ticket.
    $service = app(RunService::class);
    GameKit::play($service, $service->start($learner, $course->slug, $lesson->id), $learner);
    $learnerId = $learner->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    $results = gameRace(array_fill(0, 3, fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, $replaceActive)->id));

    // Under the learner's lock the tabs go one by one: the first takes the ticket and starts; without `replace_active` the others find
    // the run that is on - the same stop - and get it as it is (the limit is not even counted for them - and when a tab read the limit
    // a moment after the first commit, the courtesy count said "no place, no ticket" and recorded its event, the start reads the run
    // that is on once more and answers with it); with it each next tab closes the untouched run of the one before (its place AND its
    // ticket come back - G2.8b) and spends that ticket on a run of its own. One run is on at the end, the ticket is never spent twice
    // over, and SUM(ledger) = tickets.
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok', 'ok'], (string) json_encode($results))
        ->and(count(array_unique(array_column($results, 'value'))))->toBe($distinct)
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe($runs)
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->count())->toBe(1)
        ->and(GameRun::query()->where('learner_id', $learnerId)->where('free_slot', 'ticket')->count())->toBe($spends)
        ->and(GameRun::query()->where('learner_id', $learnerId)->where('finish_reason', 'abandoned')->where('answered', 0)->count())->toBe($refunds)
        ->and(gameRaceTickets($learnerId))->toBe([0, 0, $spends, $refunds])
        ->and(gameRaceEvents('game.ticket_spent'))->toHaveCount($spends)
        ->and(gameRaceEvents('game.ticket_refunded'))->toHaveCount($refunds)
        ->and(array_column(gameRaceEvents('game.ticket_refunded'), 'reason'))->toBe(array_fill(0, $refunds, 'replaced'))
        ->and(gameRaceEvents('game.run_started'))->toHaveCount($runs)
        ->and(count(gameRaceEvents('game.limit_reached')))->toBeLessThanOrEqual($replaceActive ? 0 : 2); // the stray event of the window above, same stop only
})->with([
    'the same stop: the run that is on is the answer to all' => [false, 2, 1, 1, 0],
    'replace_active: each tab starts over, the ticket of the untouched run pays for the next' => [true, 4, 3, 3, 2],
]);

it('refunds the ticket of a run closed without an answer, or counts the first answer that lands at the same instant - never both', function (string $closer): void {
    ['learnerId' => $learnerId, 'slug' => $slug, 'lessonId' => $lessonId, 'runId' => $runId] = gameRaceTicketRun();
    $run = GameRun::query()->findOrFail($runId);
    $element = GameKit::current($run);
    $answer = GameKit::rightAnswers($element)[0];

    $results = gameRace([
        fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $element['id'], $answer)->body['verdict'],
        match ($closer) {
            'an exit' => fn (): string => app(RunService::class)->abandon($runId, gameRaceLearner($learnerId))->finish_reason?->value ?? 'active',
            'a start over' => fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, true)->id,
        },
    ]);

    // Whoever locks the row first decides: the answer lands and the run keeps its ticket (closed with its answer, or still on when the
    // courtesy check of the start refused before the lock); or the run is closed, its ticket comes back, and the answer is told 410.
    $run->refresh();
    $answered = GameRunAttempt::query()->where('run_id', $runId)->count();
    [$tickets, $sum, $spends, $refunds] = gameRaceTickets($learnerId);
    expect(gameRaceCrashes($results))->toBe([])
        ->and($answered)->toBeIn([0, 1])
        ->and($run->answered)->toBe($answered)
        ->and($refunds)->toBe(1 - $answered)
        ->and($sum)->toBe($tickets)
        ->and(gameRaceEvents('game.ticket_refunded'))->toHaveCount(1 - $answered);
    if ($closer === 'an exit') {
        expect(gameRaceOutcomes($results))->toBe($answered === 1 ? ['ok', 'ok'] : ['game_run_closed', 'ok'])
            ->and($run->finish_reason)->toBe(RunFinishReason::Abandoned)
            ->and([$tickets, $spends])->toBe([1 - $answered, 1]);
    } elseif ($answered === 1) {
        // The answer first: the run holds its place and its ticket, there is no other - the start is refused by the limit.
        expect(gameRaceOutcomes($results))->toBe(['game_limit_reached', 'ok'])
            ->and($run->finish_reason)->toBeIn([null, RunFinishReason::Abandoned])
            ->and([$tickets, $spends])->toBe([0, 1])
            ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(2);
    } else {
        // The start first: it closes the untouched run, gets the ticket back and spends it on the fresh run in the same transaction.
        expect(gameRaceOutcomes($results))->toBe(['game_run_closed', 'ok'])
            ->and($run->finish_reason)->toBe(RunFinishReason::Abandoned)
            ->and([$tickets, $spends])->toBe([0, 2])
            ->and(GameRun::query()->where('active_learner_id', $learnerId)->where('free_slot', 'ticket')->count())->toBe(1);
    }
})->with(['an exit', 'a start over']);

it('refunds the ticket once when two closes of the same untouched run meet', function (string $pair, string $reason): void {
    ['learnerId' => $learnerId, 'runId' => $runId] = gameRaceTicketRun();
    $run = GameRun::query()->findOrFail($runId);
    $element = GameKit::current($run);
    $later = CarbonImmutable::parse('2026-11-05 10:31:00');

    $exit = fn (?CarbonImmutable $at = null): string => app(RunService::class)->abandon($runId, gameRaceLearner($learnerId), $at)->finish_reason?->value ?? 'active';
    $scheduler = fn (): int => app(RunService::class)->closeExpired($later);
    $read = fn (): string => app(RunService::class)->find(gameRaceLearner($learnerId), $runId, $later)->finish_reason?->value ?? 'active';
    $answer = fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0], null, $later)->body['verdict'];
    $results = gameRace(match ($pair) {
        'two exits' => [$exit, $exit],
        'an exit and the scheduler' => [fn (): string => $exit($later), $scheduler],
        'two schedulers' => [$scheduler, $scheduler],
        'a read and the scheduler' => [$read, $scheduler],
        'an answer past the time and the scheduler' => [$answer, $scheduler],
    });

    $run->refresh();
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe($pair === 'an answer past the time and the scheduler' ? ['game_run_closed', 'ok'] : ['ok', 'ok'])
        ->and($run->finish_reason?->value)->toBe($reason)
        ->and($run->answered)->toBe(0)
        ->and(GameRunAttempt::query()->where('run_id', $runId)->count())->toBe(0)
        ->and(gameRaceTickets($learnerId))->toBe([1, 1, 1, 1])
        ->and(GameTicketLedgerEntry::query()->where('idempotency_key', "refund:run:{$runId}")->count())->toBe(1)
        ->and(gameRaceEvents('game.ticket_refunded'))->toEqual([['run_id' => $runId, 'reason' => $reason === 'expired' ? 'expired' : 'exited']])
        ->and(gameRaceEvents('game.run_finished'))->toHaveCount(2); // the played free run and this one, once
})->with([
    'two exits' => ['two exits', 'abandoned'],
    'an exit and the scheduler' => ['an exit and the scheduler', 'expired'],
    'two schedulers' => ['two schedulers', 'expired'],
    'a read and the scheduler' => ['a read and the scheduler', 'expired'],
    'an answer past the time and the scheduler' => ['an answer past the time and the scheduler', 'expired'],
]);

it('keeps SUM(ledger) = tickets when a refund, a start that spends and a grant of the streak meet on one learner', function (): void {
    ['learnerId' => $learnerId, 'slug' => $slug, 'lessonId' => $lessonId, 'runId' => $runId] = gameRaceTicketRun();

    // The exit gives the ticket back under the run's row; the start takes `learners` first and either closes the run itself or finds it
    // closed - and spends the ticket that came back; the grant takes `learners` and the profile row FOR UPDATE, so the refund that lands
    // under the run's row is never overwritten by the balance the grant read (docs/18 §12.5.1 rule 18). No deadlock: the profile row
    // is always taken after `game_runs` and after `learners`, and nothing holding it waits for either.
    $results = gameRace([
        fn (): string => app(RunService::class)->abandon($runId, gameRaceLearner($learnerId))->finish_reason?->value ?? 'active',
        fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, true)->id,
        fn (): ?int => app(TicketService::class)->grantForStreak(gameRaceLearner($learnerId), 14, '2026-11-12')?->balance,
    ]);

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and(GameRun::query()->findOrFail($runId)->finish_reason)->toBe(RunFinishReason::Abandoned)
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->where('free_slot', 'ticket')->count())->toBe(1)
        ->and(gameRaceTickets($learnerId))->toBe([1, 1, 2, 1])
        ->and(gameRaceEvents('game.ticket_refunded'))->toHaveCount(1)
        ->and(gameRaceEvents('game.ticket_granted'))->toHaveCount(2);
});

it('does not deadlock a Free start against a Free opening of the practice and the lock of the learner row the training picker takes', function (): void {
    GameKit::limits(1);
    PracticeKit::openAccess();
    PracticeKit::limits(2, 1);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learnerId = $learner->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    // Three transactions, the same first lock (the learner's row), no cycle (docs/18 §12.5.1 rule 18, docs/16 §9 rule 16).
    $results = gameRace([
        fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId)->id,
        fn (): int => app(PracticeSessionService::class)->open(gameRaceLearner($learnerId), PracticeMode::Collocation, $slug, null, 'hub', false, null)->id,
        function () use ($learnerId): int {
            return DB::transaction(function () use ($learnerId): int {
                Learner::query()->whereKey($learnerId)->lock('for no key update')->value('id');
                usleep(200_000);

                return 1;
            });
        },
    ]);

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->where('free_slot', 'daily')->count())->toBe(1)
        ->and(PracticeSession::query()->where('active_learner_id', $learnerId)->count())->toBe(1);
});

it('does not deadlock the finish of a run against a Free start that replaces it: the finish holds game_runs then game_profiles, the start takes them in the same order', function (): void {
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameProfile::factory()->withTickets(1)->create(['learner_id' => $learner->id]);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], $answer);
        }
    }
    $last = GameKit::current($run);
    $answer = GameKit::rightAnswers($last)[0];
    $learnerId = $learner->id;
    $runId = $run->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    $results = gameRace([
        fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $last['id'], $answer)->run->status->value,
        fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, true)->id,
    ]);

    // Whoever locks the run first wins: the finish completes it and the start (waiting on the row) pays the ticket for a new run; or
    // the start abandons it (answered, so its place stays taken) and the answer is told the run is closed. Never a deadlock, and the
    // ticket is spent exactly once either way.
    $old = GameRun::query()->findOrFail($runId);
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBeIn([['ok', 'ok'], ['game_run_closed', 'ok']])
        ->and($old->finish_reason)->toBeIn([RunFinishReason::Completed, RunFinishReason::Abandoned])
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->where('free_slot', 'ticket')->count())->toBe(1)
        ->and(GameTicketLedgerEntry::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(GameProfile::query()->where('learner_id', $learnerId)->value('tickets'))->toBe(0);
});

it('gives the daily caravan of the day to one of two tabs at once: one row with the daily_key, the other is told game_daily_done', function (): void {
    [$learner, $course] = GameKit::stop(8);
    $learner->update(['active_course_id' => $course->id, 'timezone' => 'UTC']);
    WordRepetition::query()->where('learner_id', $learner->id)->update(['next_review_at' => now()->subHour()]);
    $learnerId = $learner->id;

    $results = gameRace(array_fill(0, 2, fn (): int => app(DailyCaravanService::class)->start(gameRaceLearner($learnerId))->id));

    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe(['game_daily_done', 'ok'])
        ->and(GameRun::query()->where('daily_key', $learnerId.':2026-11-05')->count())->toBe(1)
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']))[0]['details'])->toBe(['resets_at' => '2026-11-06T00:00:00.000Z'])
        ->and(gameRaceEvents('game.run_started'))->toHaveCount(1);
});

// ---------------------------------------------------------------- the view after the commit (G2.13, docs/18 §12.5.1 rule 24)

/**
 * A Free learner (limit 1) without an invite code, one ticket in the ledger, a city of two cleared stops, and the fight with its boss on
 * the free run of the day - played to its last element, every answer right, the last one NOT sent.
 *
 * @return array{learnerId: int, slug: string, lessonId: int, stageId: int, runId: int, elementId: string, answer: array<string, mixed>}
 */
function gameRaceFirstBossWin(): array
{
    GameKit::limits(1); // every boss open to Free (boss_stages null), one map run a day
    config(['telegram.bot_username' => 'LexiFlowBot']);
    Storage::fake('public');
    [$learner, $course, $first] = GameKit::stop(0);
    $stage = $first->stage ?? throw new RuntimeException('No stage.');
    $lessons = [$first, Lesson::factory()->for($stage)->create(['number' => 2])];
    foreach ($lessons as $l => $lesson) {
        foreach (range(1, 6) as $i) {
            GameKit::word($lesson, $learner, "bossrace{$l}x{$i}", "перевод {$l}.{$i}");
        }
        GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lesson->id]);
    }
    expect($learner->referral_code)->toBeNull();
    app(TicketService::class)->grantForStreak($learner, 7, '2026-11-05');

    $runs = app(RunService::class);
    $run = app(BossService::class)->start($learner, $course->slug, $stage->id);
    expect($run->free_slot)->toBe(FreeSlot::Daily)->and($run->total)->toBe(12);
    for ($i = 0; $i < $run->total - 1; $i++) {
        $element = GameKit::current($run);
        $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0]);
    }
    $last = GameKit::current($run);

    return ['learnerId' => $learner->id, 'slug' => $course->slug, 'lessonId' => $first->id, 'stageId' => $stage->id, 'runId' => $run->id, 'elementId' => $last['id'], 'answer' => GameKit::rightAnswers($last)[0]];
}

it('does not deadlock the first win over a boss of a learner without an invite code against a Free start that replaces the fight and a grant of the streak: the view is built after the commit', function (): void {
    ['learnerId' => $learnerId, 'slug' => $slug, 'lessonId' => $lessonId, 'stageId' => $stageId, 'runId' => $runId, 'elementId' => $elementId, 'answer' => $answer] = gameRaceFirstBossWin();

    // Before G2.13 the answer built its RunView inside the transaction: `summary.boss.share` made the invite code with an UPDATE of
    // `learners` while the run, the city, the wallet, the profile and the day were held - the inverse of rule 18. The start holds
    // `learners` and waits for the run's row; the grant holds `learners` and waits for the profile: a cycle. Now the answer holds no
    // row of the learner, the start and the grant go one after the other on `learners`, and whoever locks the run first decides.
    $results = gameRace([
        fn (): string => app(RunService::class)->answer($runId, gameRaceLearner($learnerId), (string) Str::uuid(), $elementId, $answer)->run->summary['boss']['share']['link'] ?? 'no-share',
        fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, true)->id,
        fn (): ?int => app(TicketService::class)->grantForStreak(gameRaceLearner($learnerId), 14, '2026-11-12')?->balance,
    ]);

    $boss = GameRun::query()->findOrFail($runId);
    $learner = Learner::query()->findOrFail($learnerId);
    $city = GameCityProgress::query()->where('learner_id', $learnerId)->where('stage_id', $stageId)->first();
    $cards = NotificationLog::query()->where('learner_id', $learnerId)->where('type', GameCardSender::NOTIFICATION_TYPE)->count();
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBeIn([['ok', 'ok', 'ok'], ['game_run_closed', 'ok', 'ok']], (string) json_encode($results))
        ->and($boss->finish_reason)->toBeIn([RunFinishReason::Completed, RunFinishReason::Abandoned])
        // the fight had its answers: the start closes it without a refund and pays the fresh run with the ticket; the grant adds one
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(GameRun::query()->where('active_learner_id', $learnerId)->where('kind', 'stop')->where('free_slot', 'ticket')->count())->toBe(1)
        ->and(gameRaceTickets($learnerId))->toBe([1, 1, 1, 0])
        ->and(gameRaceEvents('game.ticket_spent'))->toHaveCount(1)
        ->and(gameRaceEvents('game.ticket_granted'))->toHaveCount(2);

    if ($boss->finish_reason === RunFinishReason::Completed) {
        // The answer first: the win is paid and recorded in the database, the card is sent once, and the code the share link carries is
        // the one made AFTER the commit - the same the learner has now.
        expect($results[0]['value'])->toBe('https://t.me/LexiFlowBot?start=ref_'.$learner->referral_code)
            ->and($learner->referral_code)->not->toBeNull()
            ->and($boss->coins_awarded)->toBe(50)->and($boss->stars)->toBe(3)->and((bool) ($boss->state['boss_first'] ?? false))->toBeTrue()
            ->and($city?->boss_defeated_at)->not->toBeNull()->and($city?->boss_wins)->toBe(1)
            ->and(GameWallet::query()->where('learner_id', $learnerId)->value('balance'))->toBe(50)
            ->and(GameCoinLedgerEntry::query()->where('idempotency_key', "run:{$runId}:reward")->count())->toBe(1)
            ->and(Achievement::query()->where('learner_id', $learnerId)->where('key', 'game_first_boss')->count())->toBe(1)
            ->and(gameRaceEvents('game.boss_defeated'))->toHaveCount(1)
            ->and($cards)->toBe(1);
    } else {
        // The start first: the fight is abandoned with its answers, nothing is paid, no card, no code was made for anybody.
        expect($results[0]['code'] ?? null)->toBe('game_run_closed')
            ->and($learner->referral_code)->toBeNull()
            ->and($boss->coins_awarded)->toBe(0)->and($boss->stars)->toBeNull()
            ->and($city)->toBeNull()
            ->and(GameWallet::query()->where('learner_id', $learnerId)->exists())->toBeFalse()
            ->and(Achievement::query()->where('learner_id', $learnerId)->where('key', 'game_first_boss')->count())->toBe(0)
            ->and(gameRaceEvents('game.boss_defeated'))->toHaveCount(0)
            ->and($cards)->toBe(0);
    }
});
