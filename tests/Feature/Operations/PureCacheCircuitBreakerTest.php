<?php

declare(strict_types=1);

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Events\CacheCircuitClosed;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Domain\Operations\Support\CircuitBreakerFailoverStore;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\PureCacheStandIn;

/*
 * The second Redis instance HANGS - accepts the connection and never answers (a paused container, a swapped-out or a
 * blocked redis-server) - and the circuit breaker of the pure store's chain (docs/19 §4.1) keeps that from costing a
 * timeout per call: the first call pays it and opens the breaker, every other call of every process goes straight to the
 * array store, one probe per window, and a probe that reaches the instance again closes it. The hung instance is a
 * listening socket of this process that never calls accept(); the live one, where the environment has it, stands in for
 * the instance coming back.
 */

uses(RefreshDatabase::class);

const OPEN_SECONDS = 15;

/** Live for the whole test: a closed listening socket would refuse connections instead of swallowing them. */
$hung = null;

beforeEach(function () use (&$hung): void {
    $this->markers = PureCacheStandIn::markerDirectory();
    $hung = PureCacheStandIn::hungServer();
    $this->hung = $hung;
    PureCacheStandIn::pointPureCacheAt('127.0.0.1', PureCacheStandIn::portOf($hung), $this->markers, OPEN_SECONDS);
})->skip(! extension_loaded('redis'), 'phpredis is required to talk to a (hung) Redis');

afterEach(function () use (&$hung): void {
    if (is_resource($hung)) {
        fclose($hung);
    }
    PureCacheStandIn::removeDirectory($this->markers);
});

/** @return float seconds the call took */
function timed(callable $call): float
{
    $start = hrtime(true);
    $call();

    return (hrtime(true) - $start) / 1e9;
}

function markerOfLruRedis(): string
{
    $store = PureCache::store()->getStore();
    expect($store)->toBeInstanceOf(CircuitBreakerFailoverStore::class);

    /** @var CircuitBreakerFailoverStore $store */
    return $store->breaker()->markerPath('lru_redis');
}

it('pays the timeout once: the first call opens the breaker, the next ones do not even connect', function (): void {
    Event::fake([CacheFailedOver::class]);
    $timeout = (float) config('database.redis.lru.read_timeout');
    expect($timeout)->toBeLessThanOrEqual(0.3);

    $first = timed(fn () => expect(PureCache::store()->get('catalog:probe'))->toBeNull());
    expect($first)->toBeGreaterThanOrEqual($timeout)->toBeLessThan(2.0)
        ->and(PureCacheStandIn::connectionsReceived($this->hung))->toBeGreaterThanOrEqual(1)
        ->and(file_exists(markerOfLruRedis()))->toBeTrue();
    Event::assertDispatchedTimes(CacheFailedOver::class, 1);

    $keys = array_map(fn (int $i): string => "catalog:k{$i}", range(1, 20));
    $rest = timed(function () use ($keys): void {
        PureCache::store()->put('catalog:probe', 1, 60);
        expect(PureCache::store()->get('catalog:probe'))->toBe(1) // the array member of the chain
            ->and(PureCache::store()->many(['catalog:a', 'catalog:b']))->toBe(['catalog:a' => null, 'catalog:b' => null])
            ->and(PureCache::store()->flexible('catalog:flex', [10, 60], fn (): int => 7))->toBe(7);
        PureCache::store()->deleteMultiple($keys);
        PureCache::forgetMany($keys);
    });
    expect($rest)->toBeLessThan(0.1, 'nothing waits for the hung instance any more')
        ->and(PureCacheStandIn::connectionsReceived($this->hung))->toBe(0, 'no connection was attempted');
    Event::assertDispatchedTimes(CacheFailedOver::class, 1);
});

it('is open for a process that starts later, without a connection, and probes once per window', function (): void {
    Log::spy();
    PureCache::store()->get('catalog:probe');
    PureCacheStandIn::connectionsReceived($this->hung);
    Log::shouldHaveReceived('warning')->once()->with('cache.failed_over', Mockery::on(fn (array $context): bool => $context['store'] === 'lru_redis'));

    // Another php-fpm request: a chain of its own, no memory - the marker is what it knows.
    Cache::forgetDriver('lru');
    expect(timed(fn () => PureCache::store()->get('catalog:probe')))->toBeLessThan(0.1)
        ->and(PureCacheStandIn::connectionsReceived($this->hung))->toBe(0);
    Log::shouldHaveReceived('warning')->once();

    // The window ends: one probe, which hangs and fails - a new window, one more line.
    $this->travel(OPEN_SECONDS)->seconds();
    $openedAgainAt = now()->getTimestamp();
    expect(timed(fn () => PureCache::store()->get('catalog:probe')))->toBeGreaterThanOrEqual(0.2)
        ->and(PureCacheStandIn::connectionsReceived($this->hung))->toBeGreaterThanOrEqual(1)
        ->and(filemtime(markerOfLruRedis()))->toBe($openedAgainAt);
    Log::shouldHaveReceived('warning')->twice();

    Cache::forgetDriver('lru');
    expect(timed(fn () => PureCache::store()->get('catalog:probe')))->toBeLessThan(0.1)
        ->and(PureCacheStandIn::connectionsReceived($this->hung))->toBe(0);
    Log::shouldHaveReceived('warning')->twice();
});

it('serves /me, the readiness probe and the invalidations while the instance hangs', function (): void {
    $learner = Learner::factory()->create();
    $token = app(JwtService::class)->issue($learner->id)['token'];
    app(SchedulerHeartbeat::class)->beat();

    $first = timed(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/twa/me')->assertOk());
    $second = timed(fn () => $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/twa/me')->assertOk());
    expect($first)->toBeLessThan(2.0)->and($second)->toBeLessThan(0.5, 'the breaker is open: no timeout in the request');

    // Readiness pings the instance itself (one timeout, so the operator sees the truth) and stays 200 degraded.
    $this->getJson('/health/ready')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.redis_cache', 'degraded');

    app(CourseCatalogService::class)->forgetPublished();
    app(WordTranslationResolver::class)->forget([1, 2, 3]);
    expect(PureCacheStandIn::connectionsReceived($this->hung))->toBeGreaterThanOrEqual(1);
});

it('recovers a long-lived process: the probe after the window reaches the instance again and closes the breaker', function (): void {
    Log::spy();
    Event::fake([CacheCircuitClosed::class, CacheFailedOver::class]);
    $marker = markerOfLruRedis();

    PureCache::store()->get('catalog:probe');
    expect(file_exists($marker))->toBeTrue();
    Event::assertDispatchedTimes(CacheFailedOver::class, 1);

    // The instance is back (the live one of the environment); the worker keeps its chain and the memory of its breaker,
    // only its connection is rebuilt - which it does by itself after a lost connection.
    $live = (array) PureCacheStandIn::liveInstance();
    PureCacheStandIn::pointPureCacheAt($live['host'], $live['port'], $this->markers, OPEN_SECONDS, keepChain: true);
    expect(PureCache::store()->get('catalog:probe'))->toBeNull('still open: the window is not over');
    Event::assertNotDispatched(CacheCircuitClosed::class);

    $this->travel(OPEN_SECONDS)->seconds();
    PureCache::store()->put('catalog:probe', 'alive', 60);
    Event::assertDispatchedTimes(CacheCircuitClosed::class, 1);
    expect(file_exists($marker))->toBeFalse()
        ->and(Cache::store('lru_redis')->get('catalog:probe'))->toBe('alive', 'written to Redis, not to the array member');
    Cache::store('lru_redis')->forget('catalog:probe');
})->skip(fn (): bool => PureCacheStandIn::liveInstance() === null, 'needs the second Redis instance of the environment');

it('drops the rows of many words with ONE DEL on the instance', function (): void {
    $live = (array) PureCacheStandIn::liveInstance();
    PureCacheStandIn::pointPureCacheAt($live['host'], $live['port'], $this->markers);
    app('redis')->enableEvents();
    $commands = [];
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        if ($event->connectionName === 'lru') {
            $commands[] = [strtolower((string) $event->command), $event->parameters];
        }
    });

    $words = Word::factory()->count(10)->create();
    foreach ($words as $word) {
        WordTranslation::query()->create(['word_id' => $word->id, 'locale' => 'uz', 'translation' => 'x']);
    }
    $resolver = app(WordTranslationResolver::class);
    $ids = $words->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $resolver->map($words, 'uz');
    $commands = [];

    $resolver->forget($ids);

    $dels = array_values(array_filter($commands, fn (array $c): bool => $c[0] === 'del'));
    expect($dels)->toHaveCount(1, 'one DEL for the whole batch')
        ->and($dels[0][1][0])->toHaveCount(count($ids) * count(app(LocaleResolver::class)->supported()))
        ->and($commands)->toHaveCount(1, 'and nothing else');
    expect($resolver->map($words->fresh(), 'uz'))->each->toBe('x');
})->skip(fn (): bool => PureCacheStandIn::liveInstance() === null, 'needs the second Redis instance of the environment');

/*
 * The protocol of the chain itself, without Redis: a member that can be told to fail, in front of the array store.
 */

/** @return ArrayStore&object{down: bool, calls: int} */
function flakyChain(string $markers, int $openSeconds): ArrayStore
{
    $flaky = new class extends ArrayStore
    {
        public bool $down = false;

        public int $calls = 0;

        public function get($key)
        {
            $this->calls++;
            if ($this->down) {
                throw new RuntimeException('flaky member is down');
            }

            return parent::get($key);
        }
    };
    Cache::extend('flaky', fn () => Cache::repository($flaky));
    Config::set('cache.stores.flaky', ['driver' => 'flaky']);
    Config::set('cache.stores.chain', ['driver' => 'failover_breaker', 'stores' => ['flaky', 'array'], 'open_seconds' => $openSeconds, 'marker_path' => $markers]);
    Config::set('cache.pure_store', 'chain');

    return $flaky;
}

it('skips a failing member for the window, probes it once, and is back on it after a successful probe', function (): void {
    Log::spy();
    $flaky = flakyChain($this->markers, 5);
    $flaky->down = true;

    expect(PureCache::store()->get('k'))->toBeNull()->and($flaky->calls)->toBe(1);
    PureCache::store()->get('k');
    PureCache::store()->get('k');
    expect($flaky->calls)->toBe(1, 'open: the member is not called');
    Log::shouldHaveReceived('warning')->once()->with('cache.failed_over', Mockery::on(fn (array $c): bool => $c['store'] === 'flaky' && $c['error'] === 'flaky member is down'));

    $this->travel(5)->seconds();
    PureCache::store()->get('k');
    expect($flaky->calls)->toBe(2, 'the probe');
    PureCache::store()->get('k');
    expect($flaky->calls)->toBe(2, 'the probe failed: open again');
    Log::shouldHaveReceived('warning')->twice();

    $flaky->down = false;
    $this->travel(5)->seconds();
    PureCache::store()->put('k', 'v', 60); // put goes to the first member that answers: `flaky` is skipped? no - the window is over, so it is probed by this put
    expect(PureCache::store()->get('k'))->toBe('v')->and($flaky->calls)->toBe(3);
    Log::shouldHaveReceived('info')->once()->with('cache.recovered', ['store' => 'flaky']);
    Log::shouldHaveReceived('warning')->twice();
});

it('takes its locks from the first member even while its breaker is open: they live on the lock connection, not on the guarded instance', function (): void {
    $flaky = flakyChain($this->markers, 5);
    $flaky->down = true;
    PureCache::store()->get('k');
    expect($flaky->calls)->toBe(1);

    $lock = PureCache::store()->lock('k:lock', 10);
    expect($lock)->toBeInstanceOf(ArrayLock::class)
        ->and((new ReflectionProperty(ArrayLock::class, 'store'))->getValue($lock))->toBe($flaky)
        ->and($flaky->calls)->toBe(1);
});
