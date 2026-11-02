<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PlatformStatsService;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Operations\Services\SchedulerHeartbeat;
use App\Domain\Operations\Support\CircuitBreakerFailoverRepository;
use App\Domain\Operations\Support\CircuitBreakerFailoverStore;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\PureCacheStandIn;

/*
 * The second Redis instance (the pure cache) is unreachable: the store `lru` is a failover chain over the Redis store
 * and the array store, behind a circuit breaker (docs/19 §4.1), so everything that caches purely keeps working from the
 * database, the failover is reported once per opening of the breaker, and /health/ready says "degraded" instead of
 * failing. The dead instance is a closed port on localhost: a connection attempt is refused at once, whatever database
 * or default store the suite runs on. The hung instance - accepts and never answers - is PureCacheCircuitBreakerTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->markers = PureCacheStandIn::markerDirectory();
    PureCacheStandIn::pointPureCacheAt('127.0.0.1', PureCacheStandIn::DEAD_PORT, $this->markers);
})->skip(! extension_loaded('redis'), 'phpredis is required to talk to a (dead) Redis');

afterEach(function (): void {
    PureCacheStandIn::removeDirectory($this->markers);
});

it('resolves the pure store as the failover chain behind the breaker and still names the Redis connection behind it', function (): void {
    expect(PureCache::store())->toBeInstanceOf(CircuitBreakerFailoverRepository::class)
        ->and(PureCache::store()->getStore())->toBeInstanceOf(CircuitBreakerFailoverStore::class)
        ->and(config('cache.stores.lru.stores'))->toBe(['lru_redis', 'array'])
        ->and(PureCache::redisConnection())->toBe('lru')
        ->and(PureCache::hasFallback())->toBeTrue();
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

it('writes the failover to the log once per opening of the breaker, whatever the number of calls and processes', function (): void {
    Log::spy();

    PureCache::store()->get('catalog:probe');
    PureCache::store()->put('catalog:probe', 1, 60);
    PureCache::store()->forget('catalog:probe');
    Log::shouldHaveReceived('warning')->once()->with('cache.failed_over', Mockery::on(
        fn (array $context): bool => $context['store'] === 'lru_redis'
            && $context['exception'] === RedisException::class
            && str_contains($context['error'], 'refused'),
    ));

    // Another process (a php-fpm request of its own) finds the breaker open: no line of its own.
    Cache::forgetDriver('lru');
    PureCache::store()->get('catalog:probe');
    Log::shouldHaveReceived('warning')->once();

    // The window is over: the probe fails and re-opens - that is the next line.
    $this->travel((int) config('cache.stores.lru.open_seconds'))->seconds();
    PureCache::store()->get('catalog:probe');
    PureCache::store()->get('catalog:probe');
    Log::shouldHaveReceived('warning')->twice();
});
