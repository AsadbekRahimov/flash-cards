<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\Learner;
use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ForkSafe;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §14.2: real races of the solo practice. Several OS processes, each with its own PostgreSQL connection, hit the same
 * learner / session at the same instant. The `FOR UPDATE` of the session row, the unique `active_learner_id` and the unique
 * `(session_id, request_id)` are what the assertions rely on, so this file runs only on pgsql (SQLite :memory: is one
 * connection) and only where pcntl is available. Data is committed (no wrapping transaction): DatabaseTruncation, plus an
 * explicit truncate after each test. The battle races (BattleConcurrencyTest) arrive with 18.6.
 */

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * Runs each closure in a forked child with a fresh DB connection, all released at the same instant; returns what they gave
 * back, in task order. A refusal of the practice (PracticeException) is a result, not a crash: `['code' => ..., 'details' => ...]`.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, code?: string, details?: array<string, mixed>, error?: string}>
 */
function practiceRace(array $tasks): array
{
    // The parent must not share its socket with the children: close it before forking.
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-practice-race-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $startAt = microtime(true) + 0.4;
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $out = ['ok' => false, 'error' => 'no result'];
            try {
                ForkSafe::releaseConnections();
                DB::reconnect();
                while (microtime(true) < $startAt) {
                    usleep(500);
                }
                $out = ['ok' => true, 'value' => $task()];
            } catch (PracticeException $e) {
                $out = ['ok' => false, 'code' => $e->errorCode, 'details' => $e->details];
            } catch (Throwable $e) {
                $out = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($out));
                DB::disconnect();
                // Skip PHPUnit's shutdown handlers and destructors in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[$i] = $pid;
    }

    $results = [];
    foreach ($pids as $i => $pid) {
        pcntl_waitpid($pid, $status);
        $raw = @file_get_contents("{$dir}/{$i}.json");
        $results[$i] = $raw === false
            ? ['ok' => false, 'error' => 'worker died without a result']
            : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);

    DB::reconnect();

    return $results;
}

/** The workers that crashed (not the ones the practice refused). @param list<array<string, mixed>> $results @return list<string> */
function practiceRaceCrashes(array $results): array
{
    return array_values(array_map('strval', array_column(array_filter($results, static fn (array $r): bool => isset($r['error'])), 'error')));
}

/** A learner and a session of theirs, committed: the id of the learner, of the session and of its current element. @return array{0: int, 1: int, 2: string} */
function practiceRaceSession(string $slug, PracticeMode $mode = PracticeMode::Collocation): array
{
    $learner = Learner::factory()->create();
    $session = app(PracticeSessionService::class)->open($learner, $mode, $slug, null, 'hub', false, null);
    $view = app(PracticeSessionService::class)->view($session, $learner);

    return [$learner->id, $session->id, (string) ($view->current['id'] ?? '')];
}

it('opens exactly one session when four tabs open the same practice at the same instant', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$course] = PracticeKit::course();
    $learnerId = Learner::factory()->create()->id;
    $slug = $course->slug;

    $results = practiceRace(array_fill(0, 4, fn (): int => app(PracticeSessionService::class)->open(
        Learner::query()->findOrFail($learnerId), PracticeMode::Collocation, $slug, null, 'hub', false, null,
    )->id));

    // A tab that lost the race at the INSERT is handed the winner's session; a tab that came after the commit finds an active
    // session and gets the 409 of docs/16 §6.1 - with the winner's id in its details. Nobody gets a second session.
    $opened = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    $winner = PracticeSession::query()->where('learner_id', $learnerId)->sole();

    expect(practiceRaceCrashes($results))->toBe([])
        ->and($opened)->not->toBeEmpty()
        ->and(array_unique(array_column($opened, 'value')))->toBe([$opened[0]['value']])
        ->and($opened[0]['value'])->toBe($winner->id)
        ->and(array_unique(array_column($refused, 'code')))->each->toBe('practice_session_active')
        ->and(array_unique(array_map(fn (array $r): mixed => $r['details']['session_id'] ?? null, $refused)))->each->toBe($winner->id)
        ->and(PracticeSession::query()->where('active_learner_id', $learnerId)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'practice.session_started')->count())->toBe(1); // the losers rolled back
});

it('gives the session to one of two tabs that open DIFFERENT practices, and 409 to the other', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 6);
    [$course] = PracticeKit::course();
    $learnerId = Learner::factory()->create()->id;
    $slug = $course->slug;

    $results = practiceRace(array_map(
        fn (PracticeMode $mode): Closure => fn (): int => app(PracticeSessionService::class)->open(
            Learner::query()->findOrFail($learnerId), $mode, $slug, null, 'hub', false, null,
        )->id,
        [PracticeMode::Collocation, PracticeMode::Sentence],
    ));

    expect(practiceRaceCrashes($results))->toBe([])
        ->and(array_count_values(array_map(fn (array $r): string => $r['ok'] ? 'opened' : (string) $r['code'], $results)))->toEqual(['opened' => 1, 'practice_session_active' => 1])
        ->and(PracticeSession::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(PracticeSession::query()->where('active_learner_id', $learnerId)->count())->toBe(1);
});

it('lets two tabs that ask to REPLACE both succeed, and leaves one active session: the last one in', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 6);
    [$course] = PracticeKit::course();
    $learnerId = Learner::factory()->create()->id;
    $slug = $course->slug;

    $results = practiceRace(array_map(
        fn (PracticeMode $mode): Closure => fn (): int => app(PracticeSessionService::class)->open(
            Learner::query()->findOrFail($learnerId), $mode, $slug, null, 'hub', true, null,
        )->id,
        [PracticeMode::Collocation, PracticeMode::Sentence],
    ));

    expect(practiceRaceCrashes($results))->toBe([])
        ->and(array_column($results, 'ok'))->toBe([true, true])
        ->and(PracticeSession::query()->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(PracticeSession::query()->where('active_learner_id', $learnerId)->count())->toBe(1)
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(1)
        ->and(PracticeSession::query()->where('status', 'finished')->sole()->finish_reason)->toBe(FinishReason::Replaced)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('takes one answer to an element from two tabs with different request ids: the second is 409 answer_already_accepted', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$course] = PracticeKit::course();
    [$learnerId, $sessionId, $elementId] = practiceRaceSession($course->slug);

    $results = practiceRace(array_map(
        fn (string $requestId): Closure => fn (): array => app(PracticeSessionService::class)->answer(
            $sessionId, Learner::query()->findOrFail($learnerId), $requestId, $elementId, ['skip' => true], null,
        )->result,
        [(string) Str::uuid(), (string) Str::uuid()],
    ));

    $session = PracticeSession::query()->findOrFail($sessionId);
    expect(practiceRaceCrashes($results))->toBe([])
        ->and(array_count_values(array_map(fn (array $r): string => $r['ok'] ? 'accepted' : (string) $r['code'], $results)))->toEqual(['accepted' => 1, 'answer_already_accepted' => 1])
        ->and(PracticeAttempt::query()->where('session_id', $sessionId)->count())->toBe(1)
        ->and($session->answered)->toBe(1)
        ->and($session->state['current'])->toBe(1);
});

it('takes one answer from two tabs with the SAME request id: both get the same result, one row is written', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$course] = PracticeKit::course();
    [$learnerId, $sessionId, $elementId] = practiceRaceSession($course->slug);
    $requestId = (string) Str::uuid();

    $results = practiceRace(array_fill(0, 3, fn (): array => app(PracticeSessionService::class)->answer(
        $sessionId, Learner::query()->findOrFail($learnerId), $requestId, $elementId, ['text' => 'nonsense'], null,
    )->result));

    expect(practiceRaceCrashes($results))->toBe([])
        ->and(array_column($results, 'ok'))->toBe([true, true, true])
        ->and(array_unique(array_map(fn (array $r): string => json_encode($r['value'], JSON_THROW_ON_ERROR), $results)))->toHaveCount(1)
        ->and(PracticeAttempt::query()->where('session_id', $sessionId)->count())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($sessionId)->answered)->toBe(1);
});

it('closes an expired session exactly once when an answer and the scheduler get to it at the same instant', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$course] = PracticeKit::course();

    $closedByScheduler = 0;
    for ($round = 0; $round < 6; $round++) {
        // A fresh session each time, opened now and past its time a day and a second later.
        CarbonImmutable::setTestNow('2026-10-30 10:00:00');
        [$learnerId, $sessionId, $elementId] = practiceRaceSession($course->slug);
        CarbonImmutable::setTestNow('2026-10-31 10:00:01');

        $results = practiceRace([
            fn (): array => app(PracticeSessionService::class)->answer(
                $sessionId, Learner::query()->findOrFail($learnerId), (string) Str::uuid(), $elementId, ['skip' => true], null,
            )->result,
            fn (): int => app(PracticeSessionService::class)->closeExpired(),
        ]);
        $closedByScheduler += (int) ($results[1]['value'] ?? 0);

        // The answer is refused (410) whoever closed the session; the scheduler closes it or finds it closed.
        expect(practiceRaceCrashes($results))->toBe([])
            ->and($results[0])->toBe(['ok' => false, 'code' => 'practice_session_closed', 'details' => ['finish_reason' => 'expired']])
            ->and($results[1]['ok'])->toBeTrue();

        $row = PracticeSession::query()->findOrFail($sessionId);
        expect($row->status)->toBe(SessionStatus::Finished)
            ->and($row->finish_reason)->toBe(FinishReason::Expired)
            ->and($row->active_learner_id)->toBeNull()
            ->and($row->finished_at)->not->toBeNull()
            ->and(PracticeAttempt::query()->where('session_id', $sessionId)->count())->toBe(0);
    }

    // One event per session, however the race went, and the scheduler counted only what it closed itself.
    $events = ProductEvent::query()->where('name', 'practice.session_finished')->get();
    expect($events)->toHaveCount(6)
        ->and($events->pluck('props')->pluck('session_id')->unique())->toHaveCount(6)
        ->and($events->pluck('props')->pluck('reason')->unique()->all())->toBe(['expired'])
        ->and($closedByScheduler)->toBeLessThanOrEqual(6);
});
