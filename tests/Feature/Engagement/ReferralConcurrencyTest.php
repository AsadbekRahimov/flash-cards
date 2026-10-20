<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL for the referral reward (docs/13 §2.4, §3): each contender is a forked
 * process with its own database connection, released at the same instant, so the `referrals` row
 * locks of ReferralService are actually contended. Needs committed rows (children cannot see the
 * parent's open transaction): DatabaseTruncation, not RefreshDatabase. Skipped on sqlite.
 */

use App\Domain\Engagement\Services\ReferralService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\Referral;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races referral rewards in parallel transactions', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function referralRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-ref-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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

/**
 * Makes the race real: every contender sleeps after each `referrals` write (still inside its
 * transaction), so the claim of one process is held while the other is already trying.
 */
function referralHoldAfterWrite(int $microseconds = 200_000): void
{
    Referral::saved(static function () use ($microseconds): void {
        usleep($microseconds);
    });
}

function referralActivated(Learner $inviter, ?Learner $referee = null): Referral
{
    return Referral::factory()->activated()->create([
        'referrer_learner_id' => $inviter->id,
        'referee_learner_id' => ($referee ?? Learner::factory()->create())->id,
        'linked_at' => CarbonImmutable::now()->subDays(3),
    ]);
}

it('rewards each side exactly once when two reward jobs run at the same instant', function (): void {
    config(['referrals.reward_days' => 7]);
    $inviter = Learner::factory()->create();
    $referee = Learner::factory()->create();
    $referral = referralActivated($inviter, $referee);

    $results = referralRunInParallel(array_fill(0, 2, function () use ($referral): string {
        referralHoldAfterWrite();
        app(ReferralService::class)->reward(Referral::query()->findOrFail($referral->id), CarbonImmutable::now());

        return 'ok';
    }));

    expect(collect($results)->pluck('ok')->all())->toBe([true, true]);

    $fresh = $referral->fresh();
    $now = CarbonImmutable::now();
    expect($fresh?->status)->toBe('rewarded')
        ->and($fresh?->fullyRewarded())->toBeTrue()
        ->and(Subscription::query()->where('subscriber_id', $referee->id)->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        // Not 14: the losing process did not extend the winner's grant.
        ->and(Subscription::query()->where('subscriber_id', $referee->id)->sole()->current_period_ends_at?->diffInDays($now->addDays(7), true))->toBeLessThan(1)
        ->and(ProductEvent::query()->where('name', 'referral.rewarded')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->count())->toBe(2);
});

it('serializes the inviter cap: two referrals of one inviter rewarded at once with one slot left — one rewarded, one capped', function (): void {
    config(['referrals.max_rewards_per_30d' => 1, 'referrals.max_rewards_lifetime' => 50]);
    $inviter = Learner::factory()->create();
    $a = referralActivated($inviter);
    $b = referralActivated($inviter);

    $results = referralRunInParallel(array_map(fn (Referral $r) => function () use ($r): string {
        referralHoldAfterWrite();
        app(ReferralService::class)->reward(Referral::query()->findOrFail($r->id), CarbonImmutable::now());

        return (string) Referral::query()->findOrFail($r->id)->status;
    }, [$a, $b]));

    // Without the lock on the inviter's rows both would count 0 rewarded and both would be rewarded.
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['rejected', 'rewarded'])
        ->and(Referral::query()->where('status', 'rewarded')->count())->toBe(1)
        ->and(Referral::query()->where('reject_reason', 'referrer_capped')->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(2);
});

it('gives one code to a learner asked twice at the same instant', function (): void {
    $learner = Learner::factory()->create();

    $results = referralRunInParallel(array_fill(0, 3, fn (): string => app(ReferralService::class)->codeFor(Learner::query()->findOrFail($learner->id))));

    $codes = collect($results)->pluck('result')->unique()->values()->all();
    expect($codes)->toHaveCount(1)
        ->and($learner->fresh()?->referral_code)->toBe($codes[0]);
});

it('activates a referral once when two evaluations race', function (): void {
    config(['referrals.activation' => ['days' => 1, 'reviews_per_day' => 1, 'window_days' => 14]]);
    $inviter = Learner::factory()->create();
    $referee = Learner::factory()->create(['timezone' => 'UTC']);
    $referral = Referral::factory()->create(['referrer_learner_id' => $inviter->id, 'referee_learner_id' => $referee->id, 'linked_at' => CarbonImmutable::now()->subDay()]);
    LearnerDailyStats::factory()->create(['learner_id' => $referee->id, 'date' => CarbonImmutable::now()->toDateString(), 'reviews_count' => 5]);

    $results = referralRunInParallel(array_fill(0, 2, function () use ($referee): string {
        app(ReferralService::class)->evaluate(Learner::query()->findOrFail($referee->id), CarbonImmutable::now());

        return 'ok';
    }));

    expect(collect($results)->pluck('ok')->all())->toBe([true, true])
        ->and(ProductEvent::query()->where('name', 'referral.activated')->count())->toBe(1)
        // The sync queue rewarded it inside whichever process won the conditional UPDATE.
        ->and($referral->fresh()?->status)->toBe('rewarded')
        ->and(Subscription::query()->count())->toBe(2);
});
