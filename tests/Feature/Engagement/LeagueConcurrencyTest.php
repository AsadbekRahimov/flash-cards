<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL for the leagues (docs/13 §2.4, §3, §7.3): every contender is a forked process
 * with its own connection, released at the same instant. Placement under `FOR UPDATE SKIP LOCKED`
 * must never overfill a group nor seat a learner twice; a week must close exactly once with one
 * mailing; the first grades of a new week racing the close of the old one must get the tier the
 * close reports, and a late placement into a closing week must land before the barrier or nowhere.
 * Needs committed rows (children cannot see the parent's open transaction): DatabaseTruncation,
 * not RefreshDatabase. Skipped on sqlite.
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

/** Makes the close slow: every group row is held a while before its stamp commits. */
function leagueHoldGroupStamp(int $microseconds = 100_000): void
{
    LeagueGroup::updating(static function (LeagueGroup $group) use ($microseconds): void {
        if ($group->isDirty('closed_at')) {
            usleep($microseconds);
        }
    });
}

/**
 * An ended week (2026-10-12 … 18) of `$groups` groups × `$size` members in tiers 0, 1, 2, …; points 60, 50, 40, … per group.
 *
 * @return array{week: LeagueWeek, members: list<LeagueMember>}
 */
function leagueEndedWeek(int $groups, int $size): array
{
    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $members = [];
    foreach (range(0, $groups - 1) as $g) {
        $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $g % 5]);
        foreach (range(0, $size - 1) as $i) {
            $learner = Learner::factory()->create();
            $members[] = LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
            LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-14', 'reviews_count' => 60 - $i * 10]);
        }
    }

    return ['week' => $week, 'members' => $members];
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

it('closes a week once when three closers race: every group stamped once, one result set, one mailing', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2]);
    ['week' => $week] = leagueEndedWeek(groups: 4, size: 6);
    $now = CarbonImmutable::parse('2026-10-18 19:05:00', 'UTC');

    // QUEUE_CONNECTION=sync: the result DMs are sent (to the recording fake) inside the closing process.
    $results = leagueRunInParallel(array_fill(0, 3, function () use ($week, $now): bool {
        leagueHoldGroupStamp();

        return app(LeagueService::class)->closeWeek(LeagueWeek::query()->findOrFail($week->id), $now);
    }));

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result')->sort()->values()->all())->toBe([false, false, true])
        ->and($week->fresh()?->status)->toBe('closed')
        ->and(LeagueGroup::query()->whereNull('closed_at')->count())->toBe(0)
        ->and(LeagueMember::query()->whereNull('result')->count())->toBe(0)
        ->and(LeagueMember::query()->where('result', 'promoted')->count())->toBe(8)   // tiers 0-3 all promote their top 2
        ->and(LeagueMember::query()->where('result', 'demoted')->count())->toBe(6)    // tiers 1-3 demote their bottom 2
        ->and(ProductEvent::query()->where('name', 'league.week_closed')->count())->toBe(1)
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(24);
});

it('gives the first grades of the new week the tier the close reports while both race, finalising each group exactly once', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2, 'leagues.capacity' => 30]);
    ['week' => $week, 'members' => $members] = leagueEndedWeek(groups: 4, size: 6);
    $monday = CarbonImmutable::parse('2026-10-18 19:00:01', 'UTC'); // Monday 00:00:01 Tashkent

    // Rank 1 (promoted unless top tier), rank 6 (demoted unless tier 0) and rank 3 (stayed) of every group return at the same instant as the close.
    $comebacks = [];
    foreach (range(0, 3) as $g) {
        foreach ([0, 5, 2] as $i) {
            $comebacks[] = $members[$g * 6 + $i];
        }
    }
    $tasks = [function () use ($week, $monday): string {
        leagueHoldGroupStamp();

        return app(LeagueService::class)->closeWeek(LeagueWeek::query()->findOrFail($week->id), $monday) ? 'closed' : 'not-closed';
    }];
    foreach ($comebacks as $old) {
        $tasks[] = function () use ($old, $monday): int {
            leagueHoldAfterInsert(50_000);
            $member = app(LeagueService::class)->place(Learner::query()->findOrFail($old->learner_id), $monday);

            return $member?->tier_before ?? -1;
        };
    }

    $results = leagueRunInParallel($tasks);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[0]['result'])->toBe('closed')
        ->and($week->fresh()?->status)->toBe('closed')
        ->and(LeagueGroup::query()->where('league_week_id', $week->id)->whereNull('closed_at')->count())->toBe(0)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'league.week_closed')->count())->toBe(1)
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(24);

    // Every comeback sits in the new week in exactly the tier its (now final) verdict names.
    $newWeek = LeagueWeek::query()->where('starts_on', (new LeagueWeek)->fromDateTime('2026-10-19'))->sole();
    foreach ($comebacks as $k => $old) {
        $verdict = $old->fresh();
        $expected = max(0, min(4, (int) $verdict?->tierAfter()));
        $placed = LeagueMember::query()->where('league_week_id', $newWeek->id)->where('learner_id', $old->learner_id)->sole();

        expect($results[$k + 1]['result'])->toBe($expected, "comeback {$k}: ".json_encode($results[$k + 1]))
            ->and($placed->tier_before)->toBe($expected)
            ->and($placed->group?->tier)->toBe($expected);
    }
    expect(LeagueMember::query()->where('league_week_id', $newWeek->id)->count())->toBe(12)
        // Rank 1 of tiers 0-3 promoted (the top tier is 4), rank 6 of tiers 1-3 demoted (tier 0 cannot fall), rank 3 stayed.
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereIn('id', collect($comebacks)->pluck('id'))->where('result', 'promoted')->count())->toBe(4)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereIn('id', collect($comebacks)->pluck('id'))->where('result', 'demoted')->count())->toBe(3)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereIn('id', collect($comebacks)->pluck('id'))->where('result', 'stayed')->count())->toBe(5);
});

it('takes no seat in a week that is closing: a late placement lands before the barrier or nowhere, never unfinalised', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2, 'leagues.capacity' => 30]);
    ['week' => $week] = leagueEndedWeek(groups: 2, size: 6);
    $closeAt = CarbonImmutable::parse('2026-10-18 19:00:00', 'UTC');   // Monday 00:00:00 Tashkent — the scheduler
    $lateNow = CarbonImmutable::parse('2026-10-18 18:59:59', 'UTC');   // a request that started one second earlier: Sunday for it
    $latecomers = Learner::factory()->count(6)->create();

    $tasks = [function () use ($week, $closeAt): string {
        leagueHoldGroupStamp(200_000);

        return app(LeagueService::class)->closeWeek(LeagueWeek::query()->findOrFail($week->id), $closeAt) ? 'closed' : 'not-closed';
    }];
    foreach ($latecomers as $k => $latecomer) {
        $tasks[] = function () use ($latecomer, $lateNow, $k): int {
            // Staggered: some arrive before the barrier, some while groups are being stamped, some after.
            usleep($k * 120_000);
            leagueHoldAfterInsert(50_000);

            return app(LeagueService::class)->place(Learner::query()->findOrFail($latecomer->id), $lateNow)?->id ?? 0;
        };
    }

    $results = leagueRunInParallel($tasks);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[0]['result'])->toBe('closed')
        ->and($week->fresh()?->status)->toBe('closed')
        ->and(LeagueWeek::query()->count())->toBe(1); // nobody opened a new week: their `now` is still Sunday

    $seated = collect($results)->slice(1)->pluck('result')->filter(fn (int $id): bool => $id > 0)->values();
    // Everybody seated has a verdict, every group is stamped, and the refused ones left no row at all.
    expect(LeagueMember::query()->where('league_week_id', $week->id)->count())->toBe(12 + $seated->count())
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0)
        ->and(LeagueGroup::query()->where('league_week_id', $week->id)->whereNull('closed_at')->count())->toBe(0)
        ->and(LeagueMember::query()->whereIn('id', $seated)->count())->toBe($seated->count())
        ->and(LeagueGroup::query()->get()->every(fn (LeagueGroup $g): bool => $g->members_count === LeagueMember::query()->where('league_group_id', $g->id)->count()))->toBeTrue()
        ->and(NotificationLog::query()->where('type', 'league_result')->count())->toBe(12 + $seated->count())
        // The last ones arrived after the stamps: at least one of them had to be refused.
        ->and($seated->count())->toBeLessThan(6);
});
