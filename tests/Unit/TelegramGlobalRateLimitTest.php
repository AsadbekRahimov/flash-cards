<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\AnnounceAssignmentJob;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Jobs\PostLeaderboardJob;
use App\Jobs\RenewalReminderJob;
use App\Jobs\SendLearnerReminderJob;
use App\Jobs\SendTelegramMessageJob;
use App\Listeners\Organizations\GrantPostOrgDiscount;
use App\Listeners\Organizations\SendMemberJoinedMessage;
use App\Listeners\Organizations\SendMemberSeatedMessage;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;

/*
 * Bot-wide budget of background Telegram sends (docs/13 §9, 2R.3): the token bucket, the job
 * middleware that releases a job without a slot, and the list of senders that use it. Runs on the
 * array cache and, when the suite runs with CACHE_STORE=redis (CI), on Redis.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00.100'));
});

/** A job (or listener) as the worker would see it: `$job` is the queue's own job object. */
function throttledJob(object $job, ?QueueJob $queueJob = null): object
{
    $job->job = $queueJob ?? Mockery::mock(QueueJob::class);

    return $job;
}

it('gives out exactly N slots per second and a fresh budget in the next second', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 3);

    $granted = array_map(fn (): bool => $limiter->reserveGlobal(), range(1, 5));

    expect($granted)->toBe([true, true, true, false, false]);

    $this->travel(1)->seconds();

    expect($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserveGlobal())->toBeFalse();
});

it('shares the budget between limiter instances (workers) and is switched off by 0', function (): void {
    $a = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $b = new TelegramMessageRateLimiter(globalPerSecond: 1);

    expect($a->reserveGlobal())->toBeTrue()
        ->and($b->reserveGlobal())->toBeFalse();

    $off = new TelegramMessageRateLimiter(globalPerSecond: 0);

    expect(array_unique(array_map(fn (): bool => $off->reserveGlobal(), range(1, 50))))->toBe([true]);
});

it('does not touch the per-chat window when the global budget is used', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60, globalPerSecond: 1);

    expect($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserve(-1001))->toBe(0)
        ->and($limiter->reserve(-1001))->toBe(60);
});

it('waits for the next second in awaitGlobal and gives up after the limit', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);

    expect($limiter->reserveGlobal())->toBeTrue()
        // Time is frozen, so the second never changes: a denial after the wait.
        ->and($limiter->awaitGlobal(120))->toBeFalse();

    $this->travel(1)->seconds();

    expect($limiter->awaitGlobal(120))->toBeTrue();
});

it('hands out a short random retry delay', function (): void {
    $limiter = new TelegramMessageRateLimiter;

    $delays = array_map(fn (): int => $limiter->globalRetryDelay(), range(1, 60));

    expect(min($delays))->toBeGreaterThanOrEqual(1)->and(max($delays))->toBeLessThanOrEqual(5);
});

it('keeps the counter on Redis atomic with a TTL', function (): void {
    try {
        config(['cache.default' => 'redis']);
        Cache::store('redis')->get('ping');
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not reachable here (CI runs this test against a real Redis).');
    }

    // A second nobody else used: the key lives 2 s in Redis.
    $this->travelTo(CarbonImmutable::parse('2031-03-04 05:06:07'));
    $key = 'telegram:outbound:global:'.now()->getTimestamp();
    Cache::store('redis')->forget($key);

    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 2);

    expect($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserveGlobal())->toBeFalse()
        ->and((int) Cache::store('redis')->get($key))->toBe(3);

    /** @var RedisStore $store */
    $store = Cache::store('redis')->getStore();
    $ttl = (int) $store->connection()->ttl($store->getPrefix().$key);

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(2);

    Cache::store('redis')->forget($key);
});

// --- the job middleware ------------------------------------------------------------------------

it('lets a job run when a slot is free and releases it with a 1-5 s delay when not, without running it', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $middleware = new ThrottleTelegramOutbound($limiter);
    $ran = 0;
    $next = function () use (&$ran): string {
        $ran++;

        return 'ran';
    };

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn (int $d): bool => $d >= 1 && $d <= 5));
    $job = throttledJob(new SendLearnerReminderJob(1, CarbonImmutable::now()), $queueJob);

    expect($middleware->handle($job, $next))->toBe('ran'); // takes the only slot
    expect($middleware->handle($job, $next))->toBeNull();  // no slot: release()

    expect($ran)->toBe(1);

    $this->travel(1)->seconds();

    expect($middleware->handle($job, $next))->toBe('ran');
});

it('runs a job on the sync connection or outside a worker unthrottled (nowhere to release to)', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $limiter->reserveGlobal();
    $middleware = new ThrottleTelegramOutbound($limiter);

    $sync = throttledJob(new SendLearnerReminderJob(1, CarbonImmutable::now()), new SyncJob(app(), '{}', 'sync', 'default'));
    $bare = new SendLearnerReminderJob(1, CarbonImmutable::now());

    expect($middleware->handle($sync, fn (): string => 'ran'))->toBe('ran')
        ->and($middleware->handle($bare, fn (): string => 'ran'))->toBe('ran');
});

it('uses the singleton limiter from the container when none is given', function (): void {
    $this->app->instance(TelegramMessageRateLimiter::class, new TelegramMessageRateLimiter(globalPerSecond: 1));
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once();
    $job = throttledJob(new SendLearnerReminderJob(1, CarbonImmutable::now()), $queueJob);

    $middleware = new ThrottleTelegramOutbound;
    $middleware->handle($job, fn (): string => 'ran');
    $middleware->handle($job, fn (): string => 'ran');
});

it('puts the middleware on every background sender and keeps releases from exhausting their attempts', function (): void {
    $senders = [
        new SendLearnerReminderJob(1, CarbonImmutable::now()),
        new RenewalReminderJob(1, 30, CarbonImmutable::now()->addDays(3)),
        new AnnounceAssignmentJob(1),
        new PostLeaderboardJob(1),
        new SendTelegramMessageJob(1, 'x'),
        app(SendMemberJoinedMessage::class),
        app(SendMemberSeatedMessage::class),
        app(GrantPostOrgDiscount::class),
    ];

    foreach ($senders as $sender) {
        $classes = array_map(fn (object $m): string => $m::class, $sender->middleware());

        expect($classes)->toContain(ThrottleTelegramOutbound::class)
            // `release()` counts as an attempt: attempts are unlimited, failures are capped separately.
            ->and($sender->tries)->toBe(0, $sender::class)
            ->and($sender->maxExceptions)->toBeGreaterThanOrEqual(1);
    }

    expect(fn () => (new RenewalReminderJob(1, 30, CarbonImmutable::now()->addDays(3)))->retryUntil())->not->toThrow(Throwable::class)
        ->and(app(SendMemberJoinedMessage::class)->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});
