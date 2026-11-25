<?php

declare(strict_types=1);

use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\HandleTelegramUpdate;
use App\Jobs\Middleware\CountTelegramReplies;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * docs/34 §4в п. 2 and п. 5 (B1, B6): the pause after a Telegram 429 shared by every worker, the job middleware that puts a
 * rate-limited job back for `retry_after` without spending `maxExceptions`, and the replies counted in the per-second budget.
 * Runs on the array cache and, when the suite runs with CACHE_STORE=redis (CI), on Redis; the "two workers" test always uses Redis
 * when one is reachable.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));
    PauseProbeJob::$retryAfter = 7;
    PauseProbeJob::$runs = 0;
});

/** A background send that always gets a 429 from Telegram (the sender already removed its marker and rethrew). */
final class PauseProbeJob implements ShouldQueue
{
    use Queueable;

    public static int $retryAfter = 7;

    public static int $runs = 0;

    public int $tries = 0;

    public int $maxExceptions = 1;

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addDay();
    }

    public function handle(): void
    {
        self::$runs++;

        throw new TelegramRateLimitedException(self::$retryAfter, 'sendMessage');
    }
}

function redisLimiterOrSkip(): void
{
    try {
        config(['cache.default' => 'redis']);
        Cache::store('redis')->get('ping');
    } catch (Throwable) {
        test()->markTestSkipped('Redis is not reachable here (CI runs this test against a real Redis).');
    }
}

// --- the pause ---------------------------------------------------------------------------------

it('blocks reserveGlobal and reserve(chat) for retry_after after a 429 in a private chat, and lets them go after it', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 20, windowSeconds: 60, globalPerSecond: 25);

    $limiter->pause(5, 700900);

    expect($limiter->reserveGlobal())->toBeFalse()
        ->and($limiter->reserve(700900))->toBe(5)
        ->and($limiter->reserve(555))->toBe(0); // another chat: only the bot-wide budget of background sends waits

    $this->travel(5)->seconds();

    expect($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserve(700900))->toBe(0);
});

it('pauses only the group itself after a 429 in a group: reserveGlobal and other chats go on', function (int|string $group): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 20, windowSeconds: 60, globalPerSecond: 25);

    $limiter->pause(9, $group);

    expect($limiter->globalPauseRemaining())->toBe(0)
        ->and($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserve($group))->toBe(9)
        ->and($limiter->reserve(-100999))->toBe(0)
        ->and($limiter->reserve(555))->toBe(0);
})->with([
    'supergroup' => [-1001234567890],
    'basic group' => [-4242],
    'id as string' => ['-1001234567890'],
    'channel username' => ['@some_channel'],
]);

it('pauses the whole bot after a 429 in a private chat and after one without a chat', function (?int $chat): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 25);

    $limiter->pause(6, $chat);

    expect($limiter->globalPauseRemaining())->toBe(6)
        ->and($limiter->reserveGlobal())->toBeFalse();
})->with(['private chat' => [700901], 'no chat' => [null]]);

it('takes no slot and records nothing in the chat window while paused', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60, globalPerSecond: 1);
    $limiter->pause(2, 700901);

    foreach (range(1, 5) as $ignored) {
        $limiter->reserveGlobal();
        $limiter->reserve(700901);
    }

    $this->travel(2)->seconds();

    // The one slot of the second and the one message of the window are still free.
    expect($limiter->reserveGlobal())->toBeTrue()
        ->and($limiter->reserve(700901))->toBe(0);
});

it('pauses even when the per-second limit is switched off', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 0);
    $limiter->pause(3);

    expect($limiter->reserveGlobal())->toBeFalse();
});

it('caps the pause at 60 s and never shortens a longer one', function (): void {
    $limiter = new TelegramMessageRateLimiter;

    $limiter->pause(600, 700902);
    expect($limiter->globalPauseRemaining())->toBe(60)
        ->and($limiter->chatPauseRemaining(700902))->toBe(60);

    $limiter->pause(2, 700902);
    expect($limiter->globalPauseRemaining())->toBe(60)
        ->and($limiter->chatPauseRemaining(700902))->toBe(60);

    $this->travel(59)->seconds();
    $limiter->pause(10);
    expect($limiter->globalPauseRemaining())->toBe(10);
});

it('puts a job without a slot back for the rest of the pause, not for 1-5 s', function (): void {
    $limiter = new TelegramMessageRateLimiter;
    $limiter->pause(20);

    $delays = array_map(fn (): int => $limiter->globalRetryDelay(), range(1, 40));

    expect(min($delays))->toBeGreaterThanOrEqual(20)->and(max($delays))->toBeLessThanOrEqual(23);
});

it('shows one pause to two workers on Redis', function (): void {
    redisLimiterOrSkip();
    $this->travelTo(CarbonImmutable::parse('2031-05-06 07:08:09'));
    Cache::store('redis')->forget(TelegramMessageRateLimiter::PAUSE_KEY);

    $workerA = new TelegramMessageRateLimiter(globalPerSecond: 25);
    $workerB = new TelegramMessageRateLimiter(globalPerSecond: 25);

    $workerA->pause(8, 700903);

    expect($workerB->reserveGlobal())->toBeFalse()
        ->and($workerB->reserve(700903))->toBe(8)
        ->and($workerB->globalPauseRemaining())->toBe(8);

    // A smaller retry_after seen by the other worker does not shorten it (Lua compare-and-set).
    $workerB->pause(1);
    expect($workerA->globalPauseRemaining())->toBe(8);

    /** @var RedisStore $store */
    $store = Cache::store('redis')->getStore();
    $ttl = (int) $store->connection()->ttl($store->getPrefix().TelegramMessageRateLimiter::PAUSE_KEY);
    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(8);

    $this->travel(8)->seconds();
    expect($workerB->reserveGlobal())->toBeTrue();

    Cache::store('redis')->forget(TelegramMessageRateLimiter::PAUSE_KEY);
    Cache::store('redis')->forget('telegram:outbound:pause:'.hash('sha256', '700903'));
});

it('keeps a 429 of a group out of the bot-wide pause on Redis too', function (): void {
    redisLimiterOrSkip();
    $this->travelTo(CarbonImmutable::parse('2031-05-06 07:08:09'));
    Cache::store('redis')->forget(TelegramMessageRateLimiter::PAUSE_KEY);

    $workerA = new TelegramMessageRateLimiter(globalPerSecond: 25);
    $workerB = new TelegramMessageRateLimiter(globalPerSecond: 25);

    $workerA->pause(8, -100904);

    expect($workerB->reserveGlobal())->toBeTrue()
        ->and($workerB->reserve(-100904))->toBe(8);

    Cache::store('redis')->forget('telegram:outbound:pause:'.hash('sha256', '-100904'));
});

// --- the job middleware ------------------------------------------------------------------------

it('releases a job whose handler hit a 429 for retry_after + 0-3 s and throws nothing', function (): void {
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn (int $d): bool => $d >= 7 && $d <= 10));
    $job = new PauseProbeJob;
    $job->job = $queueJob;

    $result = (new ThrottleTelegramOutbound(new TelegramMessageRateLimiter))->handle($job, fn () => $job->handle());

    expect($result)->toBeNull()->and(PauseProbeJob::$runs)->toBe(1);
});

it('rethrows the 429 on the sync connection (nowhere to release to)', function (): void {
    expect(fn () => PauseProbeJob::dispatchSync())->toThrow(TelegramRateLimitedException::class);
});

it('keeps a job that keeps getting 429 out of failed_jobs: a real worker releases it for retry_after, maxExceptions untouched', function (): void {
    config(['queue.connections.database.table' => 'jobs', 'queue.failed.driver' => 'database-uuids']);
    Log::spy();

    PauseProbeJob::dispatch()->onConnection('database')->onQueue('tg-pause');
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 0);

    // maxExceptions = 1: a single THROWN exception would fail the job. Three 429 in a row must not.
    foreach (range(1, 3) as $round) {
        $worker->runNextJob('database', 'tg-pause', $options);

        $row = DB::table('jobs')->where('queue', 'tg-pause')->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->attempts)->toBe($round)
            // Released for retry_after (7) + 0-3 s of jitter.
            ->and((int) $row->available_at - now()->getTimestamp())->toBeGreaterThanOrEqual(7)->toBeLessThanOrEqual(10);

        $this->travel(11)->seconds();
    }

    expect(PauseProbeJob::$runs)->toBe(3)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

// --- B6: replies are counted, never held ---------------------------------------------------------

it('counts every reply in the second budget without refusing it', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 2);

    $limiter->consumeGlobal();
    $limiter->consumeGlobal();
    $limiter->consumeGlobal(); // over the limit: still counted, nothing refused

    expect($limiter->reserveGlobal())->toBeFalse()
        ->and((int) Cache::get('telegram:outbound:global:'.now()->getTimestamp()))->toBe(4);

    $this->travel(1)->seconds();
    expect($limiter->reserveGlobal())->toBeTrue();
});

it('lets a reply through even during the 429 pause and while the second is full', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 1);
    $limiter->reserveGlobal();
    $limiter->pause(30);

    $ran = false;
    $result = (new CountTelegramReplies($limiter))->handle(new HandleTelegramUpdate(['update_id' => 1]), function () use (&$ran): string {
        $ran = true;

        return 'handled';
    });

    expect($result)->toBe('handled')->and($ran)->toBeTrue()
        ->and((int) Cache::get('telegram:outbound:global:'.now()->getTimestamp()))->toBe(2);
});

it('runs the update even when the counter cannot be reached', function (): void {
    Log::spy();
    Cache::shouldReceive('getStore')->andThrow(new RuntimeException('Redis went away'));

    $result = (new CountTelegramReplies(new TelegramMessageRateLimiter))->handle(new HandleTelegramUpdate(['update_id' => 2]), fn (): string => 'handled');

    expect($result)->toBe('handled');
    Log::shouldHaveReceived('warning')->with('telegram.reply_count_failed', Mockery::any())->once();
});

it('puts the reply counter on every update job', function (): void {
    expect((new HandleTelegramUpdate(['update_id' => 3]))->middleware())
        ->toHaveCount(1)
        ->sequence(fn ($middleware) => $middleware->toBeInstanceOf(CountTelegramReplies::class));
});

it('counts the update of a real dispatch in the current second', function (): void {
    // The test environment switches the budget off (TELEGRAM_OUTBOUND_GLOBAL_PER_SECOND=0): production wiring here.
    config(['telegram.outbound.global_per_second' => 25]);
    $this->app->forgetInstance(TelegramMessageRateLimiter::class);
    $limiter = app(TelegramMessageRateLimiter::class);

    HandleTelegramUpdate::dispatchSync(['update_id' => 4]);

    expect((int) Cache::get('telegram:outbound:global:'.now()->getTimestamp()))->toBe(1)
        ->and($limiter->reserveGlobal())->toBeTrue();
});
