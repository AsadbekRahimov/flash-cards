<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PlatformStatsService;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Listeners\Cache\LogCacheFailover;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\FailoverStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * The second Redis instance (the pure cache) is unreachable: the store `lru` is a failover chain over the Redis store
 * and the array store (docs/19 §4.1), so everything that caches purely keeps working from the database, the failover
 * is reported once, and /health/ready says "degraded" instead of failing. The dead instance is a closed port on
 * localhost: a connection attempt is refused at once, whatever database or default store the suite runs on.
 */

uses(RefreshDatabase::class);

/** Nothing listens here. */
const DEAD_REDIS_PORT = 56399;

/**
 * The production chain of the pure store with the second instance unreachable. The Redis manager copies its config
 * when it is built (the default store may already have built it), so it is rebuilt with the dead connection.
 */
function pureCacheOnDeadRedis(): void
{
    Config::set('database.redis.lru', [
        ...(array) config('database.redis.lru'),
        'host' => '127.0.0.1',
        'port' => DEAD_REDIS_PORT,
        'timeout' => 0.5,
        'read_timeout' => 0.5,
        'max_retries' => 0,
    ]);

    $config = (array) config('database.redis');
    $client = (string) ($config['client'] ?? 'phpredis');
    unset($config['client']);
    app()->forgetInstance('redis');
    app()->instance('redis', new RedisManager(app(), $client, $config));

    Cache::forgetDriver('lru_redis');
    Cache::forgetDriver('lru');
    Config::set('cache.pure_store', 'lru');
    LogCacheFailover::reset();
}

beforeEach(function (): void {
    pureCacheOnDeadRedis();
})->skip(! extension_loaded('redis'), 'phpredis is required to talk to a (dead) Redis');

it('resolves the pure store as the failover chain and still names the Redis connection behind it', function (): void {
    expect(PureCache::store()->getStore())->toBeInstanceOf(FailoverStore::class)
        ->and(config('cache.stores.lru.stores'))->toBe(['lru_redis', 'array'])
        ->and(PureCache::redisConnection())->toBe('lru');
});

it('keeps /me, the entitlement, the catalog and the platform stats working from the database and reports the failover', function (): void {
    Event::fake([CacheFailedOver::class]);
    $learner = Learner::factory()->create();
    Subscription::factory()->create([
        'subscriber_type' => 'learner',
        'subscriber_id' => $learner->id,
        'current_period_ends_at' => null,
    ]);
    $course = Course::factory()->create(['status' => 'published', 'visibility' => 'public']);
    $resolver = app(EntitlementResolver::class);

    // Reads compute from the database; the second read of a request is served by the array store of the chain.
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro)
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro)
        ->and(array_map(fn ($c) => $c->slug, app(CourseCatalogService::class)->published('ru')))->toContain($course->slug)
        ->and(app(PlatformStatsService::class)->subscriptions())->toHaveKeys(['pro_learners', 'personal', 'organizations', 'expiring']);

    $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])
        ->getJson('/api/twa/me')
        ->assertOk();

    // Invalidations do not throw either: they land on the array store (the Redis value, if any, ages out by its TTL).
    app(CourseCatalogService::class)->forgetPublished();
    $resolver->forget($learner);
    PureCache::store()->put('catalog:probe', 1, 60);
    expect(PureCache::store()->forget('catalog:probe'))->toBeTrue()
        ->and(PureCache::store()->get('catalog:probe'))->toBeNull()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro);

    Event::assertDispatched(CacheFailedOver::class, fn (CacheFailedOver $event): bool => $event->storeName === 'lru_redis');
});

it('renders an admin dashboard widget from the database', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(SchedulerComparisonWidget::class)->assertOk()->assertSee('SM-2');
});

it('reports the pure-cache instance as degraded and stays ready', function (): void {
    app(SchedulerHeartbeat::class)->beat();

    $this->getJson('/health/ready')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis_cache', 'degraded')
        ->assertJsonPath('checks.db', 'ok');
});

it('writes the failover to the log as a warning, at most once a minute per store and process', function (): void {
    Log::spy();
    $event = new CacheFailedOver('lru_redis', new RuntimeException('Connection refused'));

    event($event);
    event($event);
    event($event);
    Log::shouldHaveReceived('warning')->once()->with('cache.failed_over', Mockery::on(
        fn (array $context): bool => $context['store'] === 'lru_redis'
            && $context['exception'] === RuntimeException::class
            && $context['error'] === 'Connection refused',
    ));

    $this->travel(LogCacheFailover::THROTTLE_SECONDS + 1)->seconds();
    event($event);
    Log::shouldHaveReceived('warning')->twice();

    // Another store has a line of its own.
    event(new CacheFailedOver('other', new RuntimeException('x')));
    Log::shouldHaveReceived('warning')->times(3);
});
