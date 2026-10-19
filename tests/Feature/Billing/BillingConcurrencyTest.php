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
use App\Models\ProductEvent;
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

/**
 * Makes the race deterministic: every contender sleeps between its checks and
 * its writes (model hook, inside the transaction), so without the promo row
 * lock all of them would pass the limit / already-redeemed checks against the
 * same committed state before any of them writes. With the lock they are
 * serialized and the sleep just adds N * delay to the test.
 */
function billingHoldBeforeRedemptionInsert(int $microseconds = 200_000): void
{
    PromoRedemption::creating(static function () use ($microseconds): void {
        usleep($microseconds);
    });
}

it('gives the last promo slot to exactly one of six simultaneous learners', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'LASTSLOT', 'max_redemptions' => 1]);
    $learners = Learner::factory()->count(6)->create();

    $results = billingRunInParallel($learners->map(fn (Learner $learner) => function () use ($learner): int {
        billingHoldBeforeRedemptionInsert();

        return app(PromoCodeService::class)->redeem($learner, 'LASTSLOT')->id;
    })->all());

    // Mutation check: without `lockForUpdate` on promo_codes in redeem() all six
    // read redemptions_count = 0, sleep, and all six succeed (6 redemptions).
    expect(billingCodes($results))->toBe(['ok', ...array_fill(0, 5, PromoCodeException::EXHAUSTED)])
        ->and($promo->refresh()->redemptions_count)->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('serializes a double-submitting learner on the promo row: one redemption, one subscription write, losers rejected before touching the subscription', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'DOUBLE', 'max_redemptions' => null, 'duration_days' => 30]);
    $learner = Learner::factory()->create();
    $writes = billingSignalFile(); // one line appended per `subscriptions` INSERT / UPDATE attempted, across processes

    $results = billingRunInParallel(array_map(fn (string $typed) => function () use ($learner, $typed, $writes): int {
        billingHoldBeforeRedemptionInsert();
        Subscription::saved(static function () use ($writes): void {
            file_put_contents($writes, "w\n", FILE_APPEND | LOCK_EX);
        });

        return app(PromoCodeService::class)->redeem($learner, $typed)->id;
    }, ['DOUBLE', 'DOUBLE', 'double']));

    $attemptedWrites = file_exists($writes) ? count(file($writes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0;
    billingRemoveSignalFile($writes);

    // The unique index on promo_redemptions would give the same visible end state
    // without the promo row lock, but the losers would then have *extended* the
    // winner's subscription and rolled back (3 writes). The lock makes the
    // already-redeemed pre-check authoritative: exactly one write ever happens.
    expect(billingCodes($results))->toBe(['ok', PromoCodeException::ALREADY_REDEEMED, PromoCodeException::ALREADY_REDEEMED])
        ->and($attemptedWrites)->toBe(1)
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

/*
 * Deterministic lock-order races (docs/12 §3.4 "subject -> subscription -> FK inserts").
 *
 * The first process pauses right after its UPDATE of the subscription row (model
 * hook) until the second one has signalled that it is about to take its own
 * locks, then waits a little longer so the second one is definitely parked on a
 * lock. Before the fix the first process then needed `FOR KEY SHARE` on the
 * learner (the `product_events.learner_id` FK) while the second held the learner
 * `FOR UPDATE` and waited for the subscription row -> PostgreSQL deadlock.
 */

/** A path in a fresh private directory for the signal file of one test. */
function billingSignalFile(): string
{
    $dir = sys_get_temp_dir().'/lexiflow-signal-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return "{$dir}/signal";
}

function billingWaitForFile(string $file, float $timeoutSeconds = 5.0): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (! file_exists($file) && microtime(true) < $deadline) {
        usleep(1000);
    }
}

function billingRemoveSignalFile(string $signal): void
{
    @unlink($signal);
    @rmdir(dirname($signal));
}

/** Pauses the current process after every `subscriptions` UPDATE until $signal exists, plus $holdMicroseconds. */
function billingHoldAfterSubscriptionUpdate(string $signal, int $holdMicroseconds = 500_000): void
{
    Subscription::updated(static function () use ($signal, $holdMicroseconds): void {
        billingWaitForFile($signal);
        usleep($holdMicroseconds);
    });
}

it('does not deadlock when a revoke and a promo redemption hit the same learner', function (): void {
    $learner = Learner::factory()->create();
    $admin = User::factory()->create();
    $live = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'manual', 'current_period_ends_at' => CarbonImmutable::now()->addDays(30)]);
    PromoCode::factory()->create(['code' => 'AFTERREVOKE', 'duration_days' => 10]);
    $signal = billingSignalFile();

    $results = billingRunInParallel([
        function () use ($live, $admin, $signal): int {
            billingHoldAfterSubscriptionUpdate($signal);

            return app(SubscriptionService::class)->revoke($live, $admin, 'race')->id;
        },
        function () use ($learner, $signal): int {
            usleep(150_000); // let the revoke take its locks first
            touch($signal);

            return app(PromoCodeService::class)->redeem($learner, 'AFTERREVOKE')->id;
        },
    ]);
    billingRemoveSignalFile($signal);

    expect(billingCodes($results))->toBe(['ok', 'ok'])
        ->and($live->refresh()->status)->toBe('expired')
        ->and(Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])->count())->toBe(1)
        ->and(Subscription::query()->where('provider', 'promo')->where('subscriber_id', $learner->id)->exists())->toBeTrue()
        ->and(PromoRedemption::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->where('learner_id', $learner->id)->count())->toBe(1);
});

it('does not deadlock when the expire scheduler and a manual grant hit the same learner', function (): void {
    $learner = Learner::factory()->create();
    $admin = User::factory()->create();
    $due = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo', 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);
    $until = CarbonImmutable::now()->addDays(30);
    $signal = billingSignalFile();

    $results = billingRunInParallel([
        function () use ($signal): int {
            billingHoldAfterSubscriptionUpdate($signal);

            return app(SubscriptionService::class)->expireDue(CarbonImmutable::now());
        },
        function () use ($learner, $admin, $until, $signal): int {
            usleep(150_000); // let the scheduler take its locks first
            touch($signal);

            return app(SubscriptionService::class)->grantManual($learner, Plan::Pro, $until, $admin, 'race')->id;
        },
    ]);
    billingRemoveSignalFile($signal);

    expect(billingCodes($results))->toBe(['ok', 'ok'])
        ->and($results[0]['result'])->toBe(1)
        ->and($due->refresh()->status)->toBe('expired')
        ->and(Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])->count())->toBe(1)
        ->and(Subscription::query()->where('provider', 'manual')->where('subscriber_id', $learner->id)->sole()->id)->toBe($results[1]['result'])
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->where('learner_id', $learner->id)->count())->toBe(1);
});
