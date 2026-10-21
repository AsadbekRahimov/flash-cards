<?php

declare(strict_types=1);

/*
 * Real race on PostgreSQL (docs/13 §2.4, §7.2): two Today-screen reads of the same learner at the same
 * instant, both seeing "yesterday missed, the day before held" — the streak freeze must be spent exactly
 * once (one `frozen_at`, `streak_freezes` down by one, one `streak.frozen` event) and both readers must
 * report the series as kept. Forked processes with their own connections; committed rows are needed, so
 * DatabaseTruncation instead of RefreshDatabase; sqlite `:memory:` is one connection — skipped there.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Learners\Services\StreakService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('spends exactly one freeze when two reads of the same learner race', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel connections and row-level UPDATE semantics.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * Runs every task in its own forked process (own DB connection), all released at the
 * same instant; returns what each returned or threw.
 *
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function freezeRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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
                $out = ['ok' => true, 'result' => $task()];
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
        $results[$i] = $raw === false
            ? ['ok' => false, 'error' => 'worker died without a result']
            : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);

    DB::reconnect();

    return $results;
}

it('spends exactly one freeze when two reads of the same learner race', function (): void {
    $learner = Learner::factory()->create([
        'timezone' => 'Asia/Tashkent',
        'daily_goal' => 2,
        'streak_current' => 3,
        'streak_best' => 3,
        'streak_last_date' => '2026-10-16',
        'streak_freezes' => 2,
    ]);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $now = CarbonImmutable::parse('2026-10-18 05:00:00', 'UTC');
    $today = CarbonImmutable::parse('2026-10-18', 'Asia/Tashkent');

    $results = freezeRunInParallel(array_fill(0, 4, function () use ($learner, $today, $now): array {
        $fresh = Learner::query()->findOrFail($learner->id);

        return app(StreakService::class)->current($fresh, $today, null, $now)->toArray();
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    foreach ($results as $r) {
        expect($r['result'])->toMatchArray(['current' => 3, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => true]);
    }

    $stored = Learner::query()->findOrFail($learner->id);
    expect($stored->streak_freezes)->toBe(1)
        ->and($stored->streak_current)->toBe(3)
        ->and($stored->streak_last_date?->toDateString())->toBe('2026-10-17')
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(1)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::StreakFrozen->value)->count())->toBe(1);
});

it('spends exactly one freeze when a read races the after-commit callback of today\'s goal', function (): void {
    $learner = Learner::factory()->create([
        'timezone' => 'Asia/Tashkent',
        'daily_goal' => 2,
        'streak_current' => 3,
        'streak_best' => 3,
        'streak_last_date' => '2026-10-16',
        'streak_freezes' => 2,
    ]);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $now = CarbonImmutable::parse('2026-10-18 05:00:00', 'UTC');
    $today = CarbonImmutable::parse('2026-10-18', 'Asia/Tashkent');

    $results = freezeRunInParallel([
        fn (): bool => app(StreakService::class)->apply(Learner::query()->findOrFail($learner->id), '2026-10-18', $now),
        fn (): array => app(StreakService::class)->current(Learner::query()->findOrFail($learner->id), $today, null, $now)->toArray(),
        fn (): array => app(StreakService::class)->current(Learner::query()->findOrFail($learner->id), $today, null, $now)->toArray(),
    ]);

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $stored = Learner::query()->findOrFail($learner->id);
    expect($stored->streak_freezes)->toBe(1)
        ->and($stored->streak_current)->toBe(4)
        ->and($stored->streak_last_date?->toDateString())->toBe('2026-10-18')
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::StreakFrozen->value)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(1);
});

it('spends exactly as many freezes as days were frozen when two multi-day freezes overlap', function (): void {
    $learner = Learner::factory()->create([
        'timezone' => 'Asia/Tashkent',
        'streak_current' => 3,
        'streak_best' => 3,
        'streak_last_date' => '2026-10-15',
        'streak_freezes' => 3,
    ]);
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $now = CarbonImmutable::parse('2026-10-18 05:00:00', 'UTC');

    // The tails [D-2, D-1] and [D-1] overlap on D-1: whoever gets there first wins, the other is rolled back whole.
    $results = freezeRunInParallel([
        fn (): bool => app(StreakFreezeService::class)->tryFreezeMany(Learner::query()->findOrFail($learner->id), ['2026-10-16', '2026-10-17'], $now),
        fn (): bool => app(StreakFreezeService::class)->tryFreezeMany(Learner::query()->findOrFail($learner->id), ['2026-10-17'], $now),
    ]);

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $frozen = LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count();
    $stored = Learner::query()->findOrFail($learner->id);

    expect(array_values(array_filter(array_column($results, 'result'))))->toHaveCount(1) // exactly one caller won
        ->and($frozen)->toBeIn([1, 2])
        ->and($stored->streak_freezes)->toBe(3 - $frozen) // every freeze spent has a frozen day, and vice versa
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->where('date', '2026-10-17')->whereNotNull('frozen_at')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::StreakFrozen->value)->count())->toBe($frozen);
});
