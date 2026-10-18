<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL: each contender is a forked process with its own
 * database connection, started simultaneously (file barrier), so the row locks
 * taken by PromoCodeService / SubscriptionService are actually contended.
 *
 * Needs committed rows (children cannot see the parent's open transaction), so
 * this file uses DatabaseTruncation instead of RefreshDatabase. sqlite has no
 * row locks and `:memory:` is per-connection: skipped there.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Exceptions\PromoCodeException;
use App\Domain\Billing\Services\PromoCodeService;
use App\Domain\Billing\Services\SubscriptionService;
use App\Models\Learner;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races promo redemptions and grants in parallel transactions', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

// DatabaseTruncation cleans *before* each test; the RefreshDatabase files that run
// after this one must not see the committed rows left here.
afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * Runs every task in its own forked process (own DB connection), all released
 * at the same instant; returns what each returned or threw.
 *
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, code?: string|null, message?: string}>
 */
function billingRunInParallel(array $tasks): array
{
    // The children must not inherit (and later close) the parent's socket.
    DB::disconnect();

    $dir = sys_get_temp_dir().'/lexiflow-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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
                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $outcome = [
                    'ok' => false,
                    'error' => $e::class,
                    'code' => $e instanceof BillingException ? $e->errorCode : null,
                    'message' => $e->getMessage(),
                ];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                // Leave without running the test runner's shutdown handlers in the child.
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
        /** @var array{ok: bool, result?: mixed, error?: string, code?: string|null, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

/** @param list<array{ok: bool, code?: string|null}> $results */
function billingCodes(array $results): array
{
    return collect($results)->map(fn (array $r) => $r['ok'] ? 'ok' : ($r['code'] ?? 'exception'))->sort()->values()->all();
}

it('gives the last promo slot to exactly one of two simultaneous learners', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'LASTSLOT', 'max_redemptions' => 1]);
    $a = Learner::factory()->create();
    $b = Learner::factory()->create();

    $results = billingRunInParallel([
        fn () => app(PromoCodeService::class)->redeem($a, 'LASTSLOT')->id,
        fn () => app(PromoCodeService::class)->redeem($b, 'LASTSLOT')->id,
    ]);

    expect(billingCodes($results))->toBe(['ok', PromoCodeException::EXHAUSTED])
        ->and($promo->refresh()->redemptions_count)->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('lets a double-submitting learner redeem a code only once', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'DOUBLE', 'max_redemptions' => null, 'duration_days' => 30]);
    $learner = Learner::factory()->create();

    $results = billingRunInParallel([
        fn () => app(PromoCodeService::class)->redeem($learner, 'DOUBLE')->id,
        fn () => app(PromoCodeService::class)->redeem($learner, 'DOUBLE')->id,
        fn () => app(PromoCodeService::class)->redeem($learner, 'double')->id,
    ]);

    expect(billingCodes($results))->toBe(['ok', PromoCodeException::ALREADY_REDEEMED, PromoCodeException::ALREADY_REDEEMED])
        ->and($promo->refresh()->redemptions_count)->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1);

    $sub = Subscription::query()->sole();
    expect($sub->current_period_ends_at?->diffInDays($sub->starts_at, true))->toEqualWithDelta(30.0, 0.01);
});

it('keeps one live subscription when grants and a redemption race for the same learner', function (): void {
    $learner = Learner::factory()->create();
    $admin = User::factory()->create();
    PromoCode::factory()->create(['code' => 'RACEPROMO', 'duration_days' => 10]);
    $until = CarbonImmutable::now()->addDays(30);

    $results = billingRunInParallel([
        fn () => app(SubscriptionService::class)->grantManual($learner, Plan::Pro, $until, $admin, 'a')->id,
        fn () => app(SubscriptionService::class)->grantManual($learner, Plan::Pro, $until, $admin, 'b')->id,
        fn () => app(PromoCodeService::class)->redeem($learner, 'RACEPROMO')->id,
    ]);

    expect(billingCodes($results))->toBe(['ok', 'ok', 'ok']);

    $ids = collect($results)->pluck('result')->unique();
    expect($ids)->toHaveCount(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])->count())->toBe(1)
        ->and(PromoRedemption::query()->sole()->subscription_id)->toBe($ids->first());
});

it('applies each due transition once when two expire runs overlap', function (): void {
    foreach (range(1, 6) as $_) {
        Subscription::factory()->create(['provider' => 'manual', 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);
    }
    Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);

    $results = billingRunInParallel([
        fn () => app(SubscriptionService::class)->expireDue(CarbonImmutable::now()),
        fn () => app(SubscriptionService::class)->expireDue(CarbonImmutable::now()),
    ]);

    expect(billingCodes($results))->toBe(['ok', 'ok'])
        ->and(collect($results)->sum('result'))->toBe(7)
        ->and(Subscription::query()->where('status', 'expired')->count())->toBe(6)
        ->and(Subscription::query()->where('status', 'past_due')->count())->toBe(1);
});
