<?php

declare(strict_types=1);

use App\Domain\Administration\Services\TwoFactorAttemptService;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Operations\Services\QueueHeartbeat;
use App\Domain\Operations\Services\ReadinessProbe;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Domain\Operations\Support\PureCache;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as RateLimiterService;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/*
 * Two Redis instances (docs/19_REDIS_CACHE_PLAN.md §4): the default store, the rate limiter, markers, locks and the
 * entitlement version keys stay on the non-evicting one; the pure cache (PureCache::store()) is on the evicting one.
 * Here the "evicting instance" is an array store of its own, and losing it entirely (flush) stands for eviction.
 */

uses(RefreshDatabase::class);

/** A pure store that is NOT the default store, as in production. */
function pureStoreOfItsOwn(): Repository
{
    Config::set('cache.stores.pure_test', ['driver' => 'array', 'serialize' => false]);
    Config::set('cache.pure_store', 'pure_test');

    return PureCache::store();
}

/** @param  array<string, bool|Throwable>  $pings  connection name => what ping() does */
function redisFactoryAnswering(array $pings): void
{
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->andReturnUsing(function (?string $name = null) use ($pings): object {
        $connection = Mockery::mock();
        $answer = $pings[$name ?? 'default'] ?? new RuntimeException("no such connection: {$name}");
        if ($answer instanceof Throwable) {
            $connection->shouldReceive('ping')->andThrow($answer);
        } else {
            $connection->shouldReceive('ping')->andReturn($answer);
        }

        return $connection;
    });
    app()->instance(RedisFactory::class, $factory);
}

describe('configuration', function (): void {
    it('puts only the pure store on the evicting connection and keeps its locks on the first instance', function (): void {
        // `lru` is a failover chain over the Redis store of the second instance and the array store, behind a circuit
        // breaker that skips the Redis member for a window after a failure (docs/19 §4.1).
        expect(config('cache.stores.lru'))->toBe(['driver' => 'failover_breaker', 'stores' => ['lru_redis', 'array'], 'open_seconds' => 15])
            ->and(config('cache.stores.lru_redis'))->toBe(['driver' => 'redis', 'connection' => 'lru', 'lock_connection' => 'locks'])
            ->and(config('database.redis.lru.host'))->not->toBe('')
            ->and(config('database.redis.lru.database'))->not->toBeNull()
            // A dead or hung cache fails fast: short timeouts (a healthy call takes under a millisecond), no client retries.
            ->and(config('database.redis.lru.timeout'))->toBeLessThanOrEqual(0.3)
            ->and(config('database.redis.lru.read_timeout'))->toBeLessThanOrEqual(0.3)
            ->and(config('database.redis.lru.max_retries'))->toBe(0);

        // Every other Redis store - the default `redis` among them - is on a connection of the first instance.
        foreach (config('cache.stores') as $name => $store) {
            if (! in_array($name, ['lru', 'lru_redis'], true) && ($store['driver'] ?? null) === 'redis') {
                expect($store['connection'])->not->toBe('lru', "store {$name} must not use the evicting connection");
            }
        }
        // The `locks` connection is on the same server as the queue and session connections.
        expect(config('database.redis.locks.host'))->toBe(config('database.redis.default.host'))
            ->and(config('database.redis.locks.port'))->toBe(config('database.redis.default.port'));
    });

    it('pins the limiter to the non-evicting store and the pure cache to the lru store by default and in .env.example', function (): void {
        $cacheConfig = (string) file_get_contents(config_path('cache.php'));
        $envExample = (string) file_get_contents(base_path('.env.example'));

        expect($cacheConfig)->toContain("'limiter' => env('CACHE_LIMITER_STORE', 'redis')")
            ->toContain("'pure_store' => env('CACHE_PURE_STORE', 'lru')")
            ->and($envExample)->toMatch('/^CACHE_STORE=redis$/m')
            ->toMatch('/^CACHE_LIMITER_STORE=redis$/m')
            ->toMatch('/^CACHE_PURE_STORE=lru$/m')
            ->toMatch('/^REDIS_LRU_HOST=redis-cache$/m')
            // An empty password in the example (compose and Laravel both read '', null, (null), empty, (empty) as none).
            ->toMatch('/^REDIS_PASSWORD=$/m')
            ->toMatch('/^REDIS_CACHE_PASSWORD=$/m')
            ->toMatch('/^REDIS_LRU_PASSWORD=$/m');
    });

    it('builds the RateLimiter on the limiter store, not on the pure store', function (): void {
        $pure = pureStoreOfItsOwn();
        $limiterCache = (new ReflectionProperty(RateLimiterService::class, 'cache'))->getValue(app(RateLimiterService::class));

        expect($limiterCache)->toBe(Cache::store((string) config('cache.limiter')))
            ->and($limiterCache)->not->toBe($pure);
    });
});

describe('PureCache', function (): void {
    it('hands out the configured store and names its Redis connection only when it is a Redis store', function (): void {
        $pure = pureStoreOfItsOwn();

        expect(PureCache::storeName())->toBe('pure_test')
            ->and($pure)->toBe(Cache::store('pure_test'))
            ->and($pure)->not->toBe(Cache::store())
            ->and(PureCache::redisConnection())->toBeNull()
            ->and(PureCache::hasFallback())->toBeFalse();

        Config::set('cache.pure_store', 'lru');
        expect(PureCache::redisConnection())->toBe('lru')
            ->and(PureCache::hasFallback())->toBeTrue('the chain goes on to the array store');

        // The Redis store alone, or a chain that ends with it: nowhere to fall over to.
        Config::set('cache.pure_store', 'lru_redis');
        expect(PureCache::redisConnection())->toBe('lru')->and(PureCache::hasFallback())->toBeFalse();
        Config::set('cache.stores.lru.stores', ['array', 'lru_redis']);
        Config::set('cache.pure_store', 'lru');
        expect(PureCache::hasFallback())->toBeFalse();
    });

    it('drops many keys with one call to the store and never throws', function (): void {
        $store = new class(new ArrayStore) extends CacheRepository
        {
            /** @var list<list<string>> */
            public array $calls = [];

            public function deleteMultiple($keys): bool
            {
                $this->calls[] = array_values(is_array($keys) ? $keys : iterator_to_array($keys));

                return parent::deleteMultiple($keys);
            }
        };
        app()->instance(PureCache::CONTAINER_KEY, $store);
        $store->put('a', 1, 60);
        $store->put('b', 2, 60);

        PureCache::forgetMany(['a', 'b', 'c']);
        PureCache::forgetMany([]);

        expect($store->calls)->toBe([['a', 'b', 'c']])
            ->and($store->has('a'))->toBeFalse()
            ->and($store->has('b'))->toBeFalse();

        // A store that is only the contract (a test double): the same one call.
        $spy = Mockery::spy(Repository::class);
        app()->instance(PureCache::CONTAINER_KEY, $spy);
        PureCache::forgetMany(['x', 'y']);
        $spy->shouldHaveReceived('deleteMultiple')->once()->with(['x', 'y']);

        // A store that throws: reported, not thrown - the write the invalidation follows must not fail.
        Exceptions::fake();
        $down = Mockery::mock(Repository::class);
        $down->shouldReceive('deleteMultiple')->andThrow(new RuntimeException('redis-cache is down'));
        app()->instance(PureCache::CONTAINER_KEY, $down);
        PureCache::forgetMany(['x']);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache is down');
    });

    it('resolves through the container so a test can swap the store', function (): void {
        $spy = Mockery::spy(Repository::class);
        app()->instance(PureCache::CONTAINER_KEY, $spy);

        $session = new ExamSession;
        $session->id = 5;
        app(LearningCache::class)->forgetExam($session);

        $spy->shouldHaveReceived('forget')->once()->with('exam:5:leaderboard');
    });
});

describe('losing the pure cache', function (): void {
    it('keeps rate limits, the 2FA replay guard and the heartbeat markers: they are on the other instance', function (): void {
        $pure = pureStoreOfItsOwn();

        // A pure value lands on the evicting store only.
        app(LearningCache::class)->wordsLearned([7], fn (array $ids): array => [7 => 12]);
        expect($pure->has('stats:words_learned:7'))->toBeTrue()
            ->and(Cache::has('stats:words_learned:7'))->toBeFalse();

        // Limits, the 2FA step claim and the liveness markers go to the default store.
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('login:203.0.113.7', 60);
        }
        $user = User::factory()->create();
        $twoFactor = app(TwoFactorAttemptService::class);
        for ($i = 0; $i < TwoFactorAttemptService::ACCOUNT_MAX_ATTEMPTS; $i++) {
            $twoFactor->recordFailure('admin', $user, '203.0.113.7', 'test');
        }
        $secret = app(Google2FA::class)->generateSecretKey();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        expect($twoFactor->verifyTotp('admin', $user, $secret, $code))->toBeTrue()
            ->and($twoFactor->verifyTotp('admin', $user, $secret, $code))->toBeFalse(); // the step is claimed
        app(SchedulerHeartbeat::class)->beat();
        app(QueueHeartbeat::class)->beat('high');

        // The evicting instance loses everything.
        $pure->flush();

        expect($pure->has('stats:words_learned:7'))->toBeFalse()
            ->and((int) RateLimiter::attempts('login:203.0.113.7'))->toBe(5) // a Redis store answers with a numeric string
            ->and($twoFactor->retryAfter('admin', $user, '203.0.113.7'))->not->toBeNull()
            ->and($twoFactor->verifyTotp('admin', $user, $secret, $code))->toBeFalse() // still claimed: no replay
            ->and(app(SchedulerHeartbeat::class)->lastBeatAt())->not->toBeNull()
            ->and(app(QueueHeartbeat::class)->lastBeatAt('high'))->not->toBeNull();
    });

    it('never brings a refunded Pro back: the entitlement version key is not on the evicting instance', function (): void {
        $pure = pureStoreOfItsOwn();
        $learner = Learner::factory()->create();
        $subscription = Subscription::factory()->create([
            'subscriber_type' => 'learner',
            'subscriber_id' => $learner->id,
            'current_period_ends_at' => null,
        ]);
        $resolver = app(EntitlementResolver::class);

        expect($resolver->for($learner)->plan)->toBe(Plan::Pro);
        $v0Key = EntitlementResolver::cacheKey($learner->id);
        expect($v0Key)->toEndWith(':v0')
            ->and($pure->has($v0Key))->toBeTrue()
            ->and(Cache::has($v0Key))->toBeFalse();

        // The refund: the subscription is over and the writer invalidates (SubscriptionService does; here by hand).
        $subscription->update(['status' => 'expired']);
        $resolver->forget($learner);
        $versionKey = EntitlementResolver::versionKey($learner->id);
        expect(Cache::has($versionKey))->toBeTrue()
            ->and($pure->has($versionKey))->toBeFalse()
            ->and($pure->has($v0Key))->toBeTrue(); // the stale Pro snapshot is still there, nobody reads it

        // Eviction wipes the evicting instance - the stale snapshot with it - and leaves the version where it is.
        $pure->flush();

        expect(EntitlementResolver::version($learner->id))->toBe(1)
            ->and(EntitlementResolver::cacheKey($learner->id))->toEndWith(':v1')
            ->and($resolver->for($learner)->plan)->toBe(Plan::Free)
            ->and($pure->has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();

        // Had the version key been evicted too, the reader would be back at v0 - the hazard the split prevents.
        $pure->put($v0Key, $pure->get(EntitlementResolver::cacheKey($learner->id)), 300);
        Cache::forget($versionKey);
        expect(EntitlementResolver::cacheKey($learner->id))->toBe($v0Key);
    });
});

describe('readiness of the two instances', function (): void {
    beforeEach(function (): void {
        app(SchedulerHeartbeat::class)->beat();
        Config::set('cache.pure_store', 'lru');
    });

    it('checks the pure-cache instance on its own and passes when it answers', function (): void {
        Config::set('cache.default', 'array'); // CI runs the default store on Redis: then `redis` would be reported too
        redisFactoryAnswering(['lru' => true]);

        $checks = app(ReadinessProbe::class)->run()->checks;

        expect($checks)->toHaveKey('redis_cache', 'ok')->not->toHaveKey('redis');
    });

    it('stays ready but degraded when the pure-cache instance is down while the first one answers', function (): void {
        Config::set('cache.default', 'redis');
        redisFactoryAnswering(['cache' => true, 'lru' => new RuntimeException('Connection refused')]);

        $report = app(ReadinessProbe::class)->run();

        expect($report->ok())->toBeTrue()
            ->and($report->status())->toBe('degraded')
            ->and($report->checks['redis'])->toBe('ok')
            ->and($report->checks['redis_cache'])->toBe('degraded');

        $this->getJson('/health/ready')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.redis_cache', 'degraded');
    });

    it('fails, not degrades, when the pure store has no fallback: on Redis alone every call would throw', function (): void {
        Config::set('cache.default', 'redis');
        Config::set('cache.pure_store', 'lru_redis');
        redisFactoryAnswering(['cache' => true, 'lru' => new RuntimeException('Connection refused')]);

        $report = app(ReadinessProbe::class)->run();

        expect($report->ok())->toBeFalse()
            ->and($report->status())->toBe('fail')
            ->and($report->checks['redis_cache'])->toBe('fail');
        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.redis_cache', 'fail');
    });

    it('fails, not degrades, when the first instance is down even though the pure-cache one answers', function (): void {
        Config::set('cache.default', 'redis');
        redisFactoryAnswering(['cache' => new RuntimeException('Connection refused'), 'lru' => true]);

        $report = app(ReadinessProbe::class)->run();

        expect($report->ok())->toBeFalse()->and($report->status())->toBe('fail');
        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('status', 'fail');
    });

    it('fails readiness when the first instance is down while the pure-cache one answers', function (): void {
        Config::set('cache.default', 'redis');
        redisFactoryAnswering(['cache' => false, 'lru' => true]);

        $report = app(ReadinessProbe::class)->run();

        expect($report->ok())->toBeFalse()
            ->and($report->checks['redis'])->toBe('fail')
            ->and($report->checks['redis_cache'])->toBe('ok');
    });

    it('passes with both instances up and exposes both checks over HTTP without details', function (): void {
        Config::set('cache.default', 'redis');
        redisFactoryAnswering(['cache' => true, 'lru' => true]);

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('checks.redis_cache', 'ok');
    });

    it('reports one check when the pure store shares the connection of the default store', function (): void {
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.lru_redis.connection', 'cache');
        redisFactoryAnswering(['cache' => true]);

        expect(app(ReadinessProbe::class)->run()->checks)->toHaveKey('redis', 'ok')->not->toHaveKey('redis_cache');
    });
});
