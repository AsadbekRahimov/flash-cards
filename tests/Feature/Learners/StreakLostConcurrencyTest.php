<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL around "the streak was lost" (docs/40 §2.5, §5 "F4 гонки сгорания"): StreakService::burn() is an optimistic
 * UPDATE without a lock, so parallel reads of `/today` burn the same series at once - exactly one UPDATE wins, the length of the burnt
 * series is stored once and every reader (the losers re-read the row) reports it; and a burn racing the first goal of the new series
 * never leaves a loss on a living series. Each contender is a forked process with its own connection; committed rows:
 * DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the burn of a streak on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function lostRaceInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-lost-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                // A deadlock must surface as an error, not as a hang of the suite.
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

/** @return array{current: int, lost: array{days: int, on: string}|null} what one reader of the Today screen is told */
function lostRaceRead(int $learnerId): array
{
    $learner = Learner::query()->findOrFail($learnerId);
    $state = app(StreakService::class)->current($learner, app(LearnerClock::class)->today($learner));

    return ['current' => $state->current, 'lost' => $state->lost];
}

it('stores the burnt series once and tells it to every one of four readers burning it at once', function (int $round): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00'); // Wednesday in Tashkent; Tuesday missed
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 2, 'streak_current' => 6 + $round, 'streak_best' => 20, 'streak_last_date' => '2026-10-19']);

    $results = lostRaceInParallel(array_fill(0, 4, fn (): array => lostRaceRead($learner->id)));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''))
            ->and($result['result'])->toBe(['current' => 0, 'lost' => ['days' => 6 + $round, 'on' => '2026-10-21']]);
    }
    $fresh = $learner->fresh();
    expect($fresh?->streak_current)->toBe(0)
        ->and($fresh?->streak_best)->toBe(20)
        ->and($fresh?->streak_last_date?->toDateString())->toBe('2026-10-19')
        ->and($fresh?->streak_lost_days)->toBe(6 + $round)
        ->and($fresh?->streak_lost_on?->toDateString())->toBe('2026-10-21');
})->with([1, 2, 3]);

it('never leaves a loss on the series a goal of the same day starts while readers burn the old one', function (int $round): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00');
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 2, 'streak_current' => 5, 'streak_best' => 5, 'streak_last_date' => '2026-10-19']);
    LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-21', 'reviews_count' => 2, 'goal_met_at' => now()]);

    $results = lostRaceInParallel([
        fn (): array => lostRaceRead($learner->id),
        fn (): bool => app(StreakService::class)->apply(Learner::query()->findOrFail($learner->id), '2026-10-21'),
        fn (): array => lostRaceRead($learner->id),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    // Whoever came first: the burn is cleared by the projection of today, or the projection moved the day and the burn matched nothing.
    $fresh = $learner->fresh();
    expect($fresh?->streak_current)->toBe(1)
        ->and($fresh?->streak_last_date?->toDateString())->toBe('2026-10-21')
        ->and($fresh?->streak_lost_days)->toBeNull()
        ->and($fresh?->streak_lost_on)->toBeNull()
        ->and(lostRaceRead($learner->id))->toBe(['current' => 1, 'lost' => null]);
})->with([1, 2, 3]);
