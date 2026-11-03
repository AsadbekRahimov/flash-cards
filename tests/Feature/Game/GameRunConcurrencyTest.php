<?php

declare(strict_types=1);

use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameTicketLedgerEntry;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\PracticeSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.5.4: the real races of a run. Several OS processes, each with its own PostgreSQL connection, hit the same learner / run at
 * the same instant. The `FOR UPDATE` of the run row, the unique `active_learner_id`, the unique `(run_id, request_id)` and the unique
 * `idempotency_key` of the ledger are what the assertions rely on, so this file runs only on pgsql with pcntl. Every worker sets a
 * lock timeout: a deadlock (40P01) or a wait that never ends (55P03) is a crash, never a hang. Data is committed (DatabaseTruncation).
 * The races of the Free limits and the tickets (`GameLimits`, `TicketService`, G2.8) are at the end.
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

it('spends the one ticket of a Free learner once when three tabs start at the same instant: one run, one entry spend:run:*, tickets = 0', function (bool $replaceActive, array $losers): void {
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameProfile::factory()->withTickets(1)->create(['learner_id' => $learner->id]);
    // The free run of the day is played: every further run of the day costs a ticket.
    $runs = app(RunService::class);
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    $learnerId = $learner->id;
    $slug = $course->slug;
    $lessonId = $lesson->id;

    $results = gameRace(array_fill(0, 3, fn (): int => app(RunService::class)->start(gameRaceLearner($learnerId), $slug, $lessonId, $replaceActive)->id));

    // Under the learner's lock the tabs go one by one: the first takes the ticket and starts; without `replace_active` the others find
    // the run that is on - the same stop - and get it as it is (the limit is not even counted for them); with it the second closes
    // that run (unanswered: its PLACE comes back, the ticket does not) and both are refused by the limit. The ticket is never spent twice.
    $limitRefusals = count(array_filter($losers, fn (string $code): bool => $code === 'game_limit_reached'));
    expect(gameRaceCrashes($results))->toBe([])
        ->and(gameRaceOutcomes($results))->toBe([...$losers, 'ok'])
        ->and(count(array_unique(array_column(array_filter($results, fn (array $r): bool => $r['ok']), 'value'))))->toBe(1)
        ->and(GameRun::query()->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(GameRun::query()->where('learner_id', $learnerId)->where('free_slot', 'ticket')->count())->toBe(1)
        ->and(GameTicketLedgerEntry::query()->where('learner_id', $learnerId)->where('source', 'spend')->count())->toBe(1)
        ->and(GameProfile::query()->where('learner_id', $learnerId)->value('tickets'))->toBe(0)
        ->and(gameRaceEvents('game.ticket_spent'))->toHaveCount(1)
        ->and(gameRaceEvents('game.run_started'))->toHaveCount(2)
        ->and(gameRaceEvents('game.limit_reached'))->toBe(array_fill(0, $limitRefusals, ['kind' => 'runs', 'limit' => 1]));
    foreach ($results as $r) {
        if (($r['code'] ?? null) === 'game_limit_reached') {
            expect($r['details'])->toMatchArray(['kind' => 'runs', 'limit' => 1, 'used' => 1, 'tickets' => 0]);
        }
    }
})->with([
    'the same stop: the run that is on is the answer to all' => [false, ['ok', 'ok']],
    'replace_active: the limit refuses the rest' => [true, ['game_limit_reached', 'game_limit_reached']],
]);

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
