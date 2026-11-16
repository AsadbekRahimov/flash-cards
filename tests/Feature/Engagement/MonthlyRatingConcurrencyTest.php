<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL for the monthly rating (docs/13 §7.6, §3): every contender is a forked process with its own
 * connection, released at the same instant. A month must close exactly once (one set of candidates, one event) whoever
 * runs the close; two administrators confirming at once must yield one confirmation with the places given once; the
 * refreshes of the Mini App and of the scheduler racing the close must land before the barrier or nowhere — nothing
 * writes to the scores of a month after its close. Needs committed rows (children cannot see the parent's open
 * transaction): DatabaseTruncation, not RefreshDatabase. Skipped on sqlite.
 */

use App\Domain\Engagement\Exceptions\LeagueMonthException;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Models\AuditLog;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('closes a month once when three closers race', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel transactions and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config([
        'leagues.timezone' => 'Asia/Tashkent',
        'leagues.monthly.enabled' => true,
        'leagues.monthly.daily_cap' => 300,
        'leagues.monthly.places' => 3,
        'leagues.monthly.candidates' => 5,
        'leagues.monthly.min_active_days' => 1,
        'leagues.monthly.min_account_days' => 0,
        'leagues.monthly.close_delay_minutes' => 180,
    ]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function monthRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-month-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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

/** Makes the close slow: the month row is held a while before the stamp of `pending_review` commits. */
function monthHoldClose(int $microseconds = 300_000): void
{
    LeagueMonth::updating(static function (LeagueMonth $month) use ($microseconds): void {
        if ($month->isDirty('status') && $month->status === 'pending_review') {
            usleep($microseconds);
        }
    });
}

/**
 * An ended October (2026-10-01 … 31) with `$count` learners of 60, 50, 40, … points on 10-20.
 *
 * @return array{month: LeagueMonth, learners: list<Learner>}
 */
function monthEnded(int $count): array
{
    $month = LeagueMonth::factory()->startingOn('2026-10-01')->create();
    $learners = [];
    foreach (range(0, $count - 1) as $i) {
        $learner = Learner::factory()->onboarded()->create(['created_at' => '2026-01-01 00:00:00']);
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-20', 'reviews_count' => 30 - $i * 5, 'correct_count' => 30 - $i * 5]);
        $learners[] = $learner;
    }

    return ['month' => $month, 'learners' => $learners];
}

function monthAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('admin');

    return $user;
}

it('closes a month once when three closers race: one set of candidates, one event, final ranks written once', function (): void {
    ['month' => $month] = monthEnded(6);
    $now = CarbonImmutable::parse('2026-10-31 22:05:00', 'UTC'); // 2026-11-01 03:05 Tashkent: due

    $results = monthRunInParallel(array_fill(0, 3, function () use ($month, $now): bool {
        monthHoldClose();

        return app(MonthlyRatingService::class)->closeMonth(LeagueMonth::query()->findOrFail($month->id), $now);
    }));

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result')->sort()->values()->all())->toBe([false, false, true])
        ->and($month->fresh()?->status)->toBe('pending_review')
        ->and($month->fresh()?->participants_count)->toBe(6)
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->count())->toBe(5)
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->pluck('rank')->sort()->values()->all())->toBe([1, 2, 3, 4, 5])
        ->and(LeagueMonthScore::query()->where('league_month_id', $month->id)->pluck('final_rank')->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and(ProductEvent::query()->where('name', 'league.month_closed')->count())->toBe(1);
});

it('confirms a month once when two administrators click at the same instant: one confirmation, the places given once', function (): void {
    ['month' => $month] = monthEnded(5);
    $now = CarbonImmutable::parse('2026-10-31 22:05:00', 'UTC');
    app(MonthlyRatingService::class)->closeMonth($month, $now);
    $admins = [monthAdmin(), monthAdmin()];

    $results = monthRunInParallel(array_map(fn (User $admin) => function () use ($month, $admin): string {
        try {
            app(MonthlyRatingReviewService::class)->confirm(LeagueMonth::query()->findOrFail($month->id), User::query()->findOrFail($admin->id), '10.0.0.1');

            return 'confirmed';
        } catch (LeagueMonthException $e) {
            return $e->errorCode;
        }
    }, $admins));

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result')->sort()->values()->all())->toBe(['confirmed', LeagueMonthException::MONTH_NOT_PENDING])
        ->and($month->fresh()?->status)->toBe('confirmed')
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'winner')->pluck('place')->sort()->values()->all())->toBe([1, 2, 3])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_CONFIRMED)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'league.month_confirmed')->count())->toBe(1);
});

it('lets a confirmation that races the close land after it or be refused — never on a half-closed month', function (): void {
    ['month' => $month] = monthEnded(5);
    $now = CarbonImmutable::parse('2026-10-31 22:05:00', 'UTC');
    $admin = monthAdmin();

    $results = monthRunInParallel([
        function () use ($month, $now): string {
            monthHoldClose(400_000);

            return app(MonthlyRatingService::class)->closeMonth(LeagueMonth::query()->findOrFail($month->id), $now) ? 'closed' : 'not-closed';
        },
        function () use ($month, $admin): string {
            usleep(150_000); // while the closer holds the month row
            try {
                app(MonthlyRatingReviewService::class)->confirm(LeagueMonth::query()->findOrFail($month->id), User::query()->findOrFail($admin->id));

                return 'confirmed';
            } catch (LeagueMonthException $e) {
                return $e->errorCode;
            }
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[0]['result'])->toBe('closed')
        ->and($results[1]['result'])->toBeIn(['confirmed', LeagueMonthException::MONTH_NOT_PENDING]);

    $fresh = $month->fresh();
    if ($results[1]['result'] === 'confirmed') {
        // It waited for the close and found a complete `pending_review` month: the winners are the real candidates.
        expect($fresh?->status)->toBe('confirmed')
            ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'winner')->count())->toBe(3);
    } else {
        expect($fresh?->status)->toBe('pending_review')
            ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('status', 'winner')->count())->toBe(0);
    }
    expect(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->count())->toBe(5);
});

it('lets the refreshes of the Mini App and of the scheduler racing the close land before the barrier or nowhere: nothing that starts after the barrier writes', function (): void {
    ['month' => $month, 'learners' => $learners] = monthEnded(8);
    $closeAt = CarbonImmutable::parse('2026-10-31 22:00:00', 'UTC');
    $late = $closeAt->addMinutes(1);
    $barrierFile = sys_get_temp_dir().'/lexiflow-month-barrier-'.getmypid().'-'.bin2hex(random_bytes(4));

    $tasks = [function () use ($month, $closeAt, $barrierFile): string {
        // The instant the barrier (`open → closing`) commits, in wall-clock time; then a slow recompute.
        LeagueMonth::updating(static function (LeagueMonth $m) use ($barrierFile): void {
            if ($m->isDirty('status') && $m->status === 'closing') {
                DB::afterCommit(static fn () => file_put_contents($barrierFile, (string) microtime(true)));
            }
        });
        monthHoldClose(300_000);
        usleep(150_000); // some refreshers get in before the barrier

        return app(MonthlyRatingService::class)->closeMonth(LeagueMonth::query()->findOrFail($month->id), $closeAt) ? 'closed' : 'not-closed';
    }];
    foreach ($learners as $k => $learner) {
        $tasks[] = function () use ($month, $learner, $late, $k): array {
            usleep($k * 60_000); // staggered: before the barrier, during the recompute, after the stamp
            $started = microtime(true);
            $written = app(MonthlyRatingService::class)->refreshLearner(LeagueMonth::query()->findOrFail($month->id), Learner::query()->findOrFail($learner->id), $late);

            return [$written ? 'written' : 'skipped', $started];
        };
    }
    $tasks[] = function () use ($month, $late): array {
        usleep(90_000);
        $started = microtime(true);
        $written = app(MonthlyRatingService::class)->refresh(LeagueMonth::query()->findOrFail($month->id), $late, full: true);

        return [$written ? 'written' : 'skipped', $started];
    };

    $results = monthRunInParallel($tasks);
    $barrierAt = (float) @file_get_contents($barrierFile);
    @unlink($barrierFile);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[0]['result'])->toBe('closed')
        ->and($barrierAt)->toBeGreaterThan(0.0)
        ->and($month->fresh()?->status)->toBe('pending_review')
        ->and(LeagueMonthScore::query()->where('league_month_id', $month->id)->count())->toBe(8);

    // Whoever started after the barrier's commit was refused; the early ones wrote (no deadlock, no error either way).
    $outcomes = collect($results)->slice(1)->map(fn (array $r): array => $r['result']);
    foreach ($outcomes as $i => [$outcome, $started]) {
        if ($started > $barrierAt) {
            expect($outcome)->toBe('skipped', "task {$i} started after the barrier and still wrote");
        }
    }
    expect($outcomes->pluck(0)->contains('skipped'))->toBeTrue(json_encode($results))
        ->and($outcomes->pluck(0)->contains('written'))->toBeTrue(json_encode($results));

    // And the final points are exactly the committed day rows, with the final ranks of the close.
    foreach ($learners as $learner) {
        $day = LearnerDailyStats::query()->where('learner_id', $learner->id)->sole();
        $score = LeagueMonthScore::query()->where('league_month_id', $month->id)->where('learner_id', $learner->id)->sole();
        expect($score->points)->toBe((int) $day->reviews_count + (int) $day->correct_count)
            ->and($score->final_rank)->not->toBeNull();
    }
});
