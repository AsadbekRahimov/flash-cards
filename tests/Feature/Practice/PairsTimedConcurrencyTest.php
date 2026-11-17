<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\Learner;
use App\Models\PracticeRecord;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ForkSafe;
use Tests\Support\Practice\PairsTimedKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §14.2 (B3): real races of "Pairs against the clock". Several OS processes, each with its own PostgreSQL connection, hit the
 * same game: two answers to the last pair of a round, an answer against the lazy close of the clock, two closes of one game. The lock of
 * the session row serialises them, the record is written under it (`practice_sessions` -> `practice_records`). PostgreSQL and pcntl only.
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

    CarbonImmutable::setTestNow('2026-11-18 10:00:00');
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
 * Runs each closure in a forked child with a fresh connection, all released at the same instant; what they gave back, in task order.
 * A refusal of the practice is a result: `['code' => ..., 'details' => ...]`.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array<string, mixed>>
 */
function pairsTimedRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-pairs-timed-race-'.bin2hex(random_bytes(4));
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
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[$i] = $pid;
    }

    $results = [];
    foreach ($pids as $i => $pid) {
        pcntl_waitpid($pid, $status);
        $raw = @file_get_contents("{$dir}/{$i}.json");
        $results[$i] = $raw === false ? ['ok' => false, 'error' => 'worker died without a result'] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);
    DB::reconnect();

    return $results;
}

/** @param list<array<string, mixed>> $results @return list<string> */
function pairsTimedRaceCrashes(array $results): array
{
    return array_values(array_map('strval', array_column(array_filter($results, static fn (array $r): bool => isset($r['error'])), 'error')));
}

/**
 * A learner with a started game (clock at T0), `$matched` pairs of the first round already matched right: committed.
 *
 * @return array{0: int, 1: int, 2: string, 3: string, 4: string} the learner, the session, the current round, its open word and the card that is its own
 */
function pairsTimedRaceGame(int $matched): array
{
    [$learner, $course] = PairsTimedKit::world();
    $service = app(PracticeSessionService::class);
    $session = $service->open($learner, PracticeMode::PairsTimed, $course->slug, null, 'hub', false, null);
    $service->start($session, $learner);

    $element = $session->snapshot['elements'][0];
    $lefts = array_column($element['left'], 'id');
    for ($i = 0; $i < $matched; $i++) {
        $service->answer($session->id, $learner, (string) Str::uuid(), $element['id'], ['left_id' => $lefts[$i], 'right_id' => $element['pairs'][$lefts[$i]]], null);
    }

    return [$learner->id, $session->id, $element['id'], $lefts[$matched], $element['pairs'][$lefts[$matched]]];
}

it('gives one point for the last pair of a round that two tabs match at the same instant: one is right, the other finds it matched', function (): void {
    [$learnerId, $sessionId, $elementId, $left, $right] = pairsTimedRaceGame(3);

    $results = pairsTimedRace(array_map(
        fn (): Closure => fn (): string => app(PracticeSessionService::class)->answer(
            $sessionId, Learner::query()->findOrFail($learnerId), (string) Str::uuid(), $elementId, ['left_id' => $left, 'right_id' => $right], null,
        )->result['verdict'],
        [1, 2, 3],
    ));

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
    $row = PracticeSession::query()->findOrFail($sessionId);

    expect(pairsTimedRaceCrashes($results))->toBe([])
        ->and($ok)->toHaveCount(1)
        ->and($ok[0]['value'])->toBe('correct')
        ->and(array_unique(array_column($refused, 'code')))->each->toBeIn(['pair_already_matched', 'element_not_current', 'answer_already_accepted'])
        ->and($row->score)->toBe(4)
        ->and($row->answered)->toBe(1);
});

it('does not let an answer score after the clock: against the closes of the game it is refused, the score stays, one event and one record', function (): void {
    [$learnerId, $sessionId, $elementId, $left, $right] = pairsTimedRaceGame(2);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:01:02')); // 2 s past the deadline, 0.5 s past the grace

    $answer = fn (): string => app(PracticeSessionService::class)->answer(
        $sessionId, Learner::query()->findOrFail($learnerId), (string) Str::uuid(), $elementId, ['left_id' => $left, 'right_id' => $right], null,
    )->result['verdict'];
    $read = fn (): string => app(PracticeSessionService::class)->find(Learner::query()->findOrFail($learnerId), $sessionId)->status->value;
    $sweep = fn (): int => app(PracticeSessionService::class)->closeExpired();

    $results = pairsTimedRace([$answer, $read, $read, $sweep, $answer]);

    $row = PracticeSession::query()->findOrFail($sessionId);
    expect(pairsTimedRaceCrashes($results))->toBe([])
        ->and($row->status->value)->toBe('finished')
        ->and($row->finish_reason->value)->toBe('time_over')
        ->and($row->score)->toBe(2)
        ->and(array_column(array_filter([$results[0], $results[4]], fn (array $r): bool => ! $r['ok']), 'code'))->each->toBeIn(['practice_time_over', 'practice_session_closed'])
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1)
        ->and(PracticeRecord::query()->count())->toBe(1)
        ->and(PracticeRecord::query()->sole())->best_score->toBe(2)->best_session_id->toBe($sessionId);
});

it('starts the clock once when two tabs press start at the same instant', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $session = app(PracticeSessionService::class)->open($learner, PracticeMode::PairsTimed, $course->slug, null, 'hub', false, null);

    $results = pairsTimedRace(array_map(
        fn (int $tab): Closure => function () use ($tab, $session, $learner): string {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 10:00:00')->addMilliseconds($tab * 50));

            return (string) app(PracticeSessionService::class)->start($session->id, Learner::query()->findOrFail($learner->id))->timed['deadline_at']?->format('Y-m-d H:i:s.v');
        },
        [0, 1, 2],
    ));

    fwrite(STDERR, json_encode($results)."\n");
    expect(pairsTimedRaceCrashes($results))->toBe([])
        ->and(array_unique(array_column($results, 'value')))->toHaveCount(1)
        ->and(PracticeSession::query()->findOrFail($session->id)->deadline_at)->not->toBeNull();
});
