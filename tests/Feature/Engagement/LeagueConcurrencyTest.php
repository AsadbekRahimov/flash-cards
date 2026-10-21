<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL for the leagues (docs/13 §2.4, §3): every contender is a forked process
 * with its own connection, released at the same instant. Placement under `FOR UPDATE SKIP LOCKED`
 * must never overfill a group nor seat a learner twice; a week must close exactly once with one
 * mailing. Needs committed rows (children cannot see the parent's open transaction):
 * DatabaseTruncation, not RefreshDatabase. Skipped on sqlite.
 */

use App\Domain\Engagement\Services\LeagueService;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\NotificationLog;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('never overfills a league group when placements race', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel transactions and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent']);
});

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function leagueRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-league-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $startAt = microtime(true) + 0.5;
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

/** Makes the race real: every placement holds its group row a while before committing. */
function leagueHoldAfterInsert(int $microseconds = 150_000): void
{
    LeagueMember::created(static function () use ($microseconds): void {
        usleep($microseconds);
    });
}

it('never overfills a league group when placements race: every learner in exactly one group, no group above capacity', function (): void {
    config(['leagues.capacity' => 5]);
    $learners = Learner::factory()->count(16)->create();
    $now = CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC');

    $results = leagueRunInParallel($learners->map(fn (Learner $l) => function () use ($l, $now): int {
        leagueHoldAfterInsert();
        $member = app(LeagueService::class)->place(Learner::query()->findOrFail($l->id), $now);

        return $member?->league_group_id ?? 0;
    })->all());

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results));

    $groups = LeagueGroup::query()->get();
    $members = LeagueMember::query()->get();

    expect(LeagueWeek::query()->count())->toBe(1)
        ->and($members->count())->toBe(16)
        ->and($members->pluck('learner_id')->unique()->count())->toBe(16)
        ->and($groups->max('members_count'))->toBeLessThanOrEqual(5)
        ->and($groups->sum('members_count'))->toBe(16)
        ->and($groups->count())->toBeGreaterThanOrEqual(4);

    // `members_count` is exactly the number of rows of each group, and every answer names the real group.
    foreach ($groups as $group) {
        expect($members->where('league_group_id', $group->id)->count())->toBe($group->members_count, "group {$group->id}");
    }
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe($members->pluck('league_group_id')->sort()->values()->all())
        ->and(ProductEvent::query()->where('name', 'league.placed')->count())->toBe(16);
});

it('seats one learner once when the same placement races with itself', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC');

    $results = leagueRunInParallel(array_fill(0, 4, function () use ($learner, $now): int {
        leagueHoldAfterInsert();

        return (int) app(LeagueService::class)->place(Learner::query()->findOrFail($learner->id), $now)?->id;
    }));

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result')->unique()->count())->toBe(1)
        ->and(LeagueMember::query()->count())->toBe(1)
        ->and(LeagueGroup::query()->count())->toBe(1)
        ->and(LeagueGroup::query()->sole()->members_count)->toBe(1)
        ->and(ProductEvent::query()->where('name', 'league.placed')->count())->toBe(1);
});

it('closes a week once when two closers race: one result set, one mailing', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2]);
    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1]);
    foreach (range(1, 6) as $i) {
        $learner = Learner::factory()->create();
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-14', 'reviews_count' => $i * 10]);
    }
    $now = CarbonImmutable::parse('2026-10-18 19:05:00', 'UTC');

    // QUEUE_CONNECTION=sync: the result DMs are sent (to the recording fake) inside the closing process.
    $results = leagueRunInParallel(array_fill(0, 3, fn (): bool => app(LeagueService::class)->closeWeek(LeagueWeek::query()->findOrFail($week->id), $now)));

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result')->sort()->values()->all())->toBe([false, false, true])
        ->and($week->fresh()?->status)->toBe('closed')
        ->and(LeagueMember::query()->whereNull('result')->count())->toBe(0)
        ->and(LeagueMember::query()->where('result', 'promoted')->count())->toBe(2)
        ->and(LeagueMember::query()->where('result', 'demoted')->count())->toBe(2)
        ->and(ProductEvent::query()->where('name', 'league.week_closed')->count())->toBe(1)
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(6);
});
