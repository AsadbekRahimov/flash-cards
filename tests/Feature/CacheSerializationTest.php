<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PlatformStatsService;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\SchedulerComparisonWidget;
use App\Models\Course;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\LeagueGroup;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * `cache.serializable_classes = false` (the Laravel 13 default against PHP gadget chains): a cache store refuses to
 * build objects, so a cache entry is a scalar or an array of scalars - a DTO, a model, Carbon or an enum comes back as
 * __PHP_Incomplete_Class. The array store of a test keeps values as they are (`serialize` = false) and would never notice
 * an object in the cache, so these tests make it serialize like redis / file / database do, with the same restriction,
 * and read every cache-backed value back from it.
 */

beforeEach(function (): void {
    config(['cache.serializable_classes' => false, 'cache.stores.array.serialize' => true]);
    Cache::purge();
});

/** True when the value is built of null, scalars and arrays only - all a cache entry may hold. */
function cacheEntryIsPlain(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (! cacheEntryIsPlain($item)) {
                return false;
            }
        }

        return true;
    }

    return $value === null || is_scalar($value);
}

it('is configured to unserialize no class from the cache and to keep the session as JSON', function (): void {
    $cache = require config_path('cache.php');
    $session = require config_path('session.php');

    expect($cache['serializable_classes'])->toBeFalse()
        ->and($session['serialization'])->toBe('json');
});

it('gives an object back from the cache as an incomplete class, which is why nothing may cache one', function (): void {
    Cache::put('probe:object', new ArrayObject(['a' => 1]), 60);
    Cache::put('probe:array', ['a' => 1, 'b' => [true, null, 1.5, 'x']], 60);

    expect(Cache::get('probe:object'))->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and(Cache::get('probe:array'))->toBe(['a' => 1, 'b' => [true, null, 1.5, 'x']]);
});

it('serves the standings of a league group from the cache as the same rows', function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent', 'leagues.capacity' => 30]);
    $now = CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC');
    $this->travelTo($now);

    $service = app(LeagueService::class);
    $ann = Learner::factory()->create(['first_name' => 'Ann']);
    $bob = Learner::factory()->create(['first_name' => 'Bob']);
    LearnerDailyStats::factory()->create(['learner_id' => $ann->id, 'date' => '2026-10-21', 'reviews_count' => 3, 'correct_count' => 0, 'new_words_count' => 0]);
    LearnerDailyStats::factory()->create(['learner_id' => $bob->id, 'date' => '2026-10-21', 'reviews_count' => 9, 'correct_count' => 0, 'new_words_count' => 0]);
    $service->place($ann, $now);
    $service->place($bob, $now);
    $group = LeagueGroup::query()->sole();

    $computed = $service->standings($group, $now);
    $key = LeagueService::CACHE_PREFIX.$group->id;

    expect(PureCache::store()->has($key))->toBeTrue()
        ->and(cacheEntryIsPlain(PureCache::store()->get($key)))->toBeTrue();

    $cached = $service->standings($group, $now);

    expect($computed)->toHaveCount(2)
        ->and($cached->all())->toEqual($computed->all())
        ->and($cached->first()?->name)->toBe('Bob')
        ->and($cached->first()?->rank)->toBe(1);
});

it('shows the SM-2 / FSRS comparison from the cache on the second render', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(SchedulerComparisonWidget::class)->assertOk()->assertSee('SM-2 —');

    $key = WidgetCache::key(SchedulerComparisonWidget::WIDGET, 'all');

    expect(PureCache::store()->has($key))->toBeTrue()
        ->and(cacheEntryIsPlain(PureCache::store()->get($key)))->toBeTrue();

    // Served from the cache this time: a class in it would have come back incomplete and broken the widget.
    Livewire::test(SchedulerComparisonWidget::class)->assertOk()->assertSee('SM-2 —');
});

it('serves the entitlement of a learner from the cache as the same plan', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);

    $computed = $resolver->for($learner);
    $key = EntitlementResolver::cacheKey($learner->id);

    expect(cacheEntryIsPlain(PureCache::store()->get($key)))->toBeTrue()
        ->and(PureCache::store()->get($key))->toBeArray()
        ->and($resolver->for($learner))->toEqual($computed);
});

it('serves the published course catalog and an exam leaderboard from the cache as the same rows', function (): void {
    Course::factory()->published()->create(['visibility' => 'public', 'slug' => 'cache-probe']);
    $catalog = app(CourseCatalogService::class);

    $computed = $catalog->published('ru');

    expect($computed)->not->toBe([])
        ->and($catalog->published('ru'))->toEqual($computed);

    $session = ExamSession::factory()->create();
    ExamResult::factory()->create(['exam_session_id' => $session->id, 'rank' => 1, 'total_score' => 90]);
    $cache = app(LearningCache::class);

    $leaderboard = $cache->examLeaderboard($session);

    expect($leaderboard['total_participants'])->toBe(1)
        ->and($cache->examLeaderboard($session))->toBe($leaderboard);
});

it('serves the admin dashboard figures from the cache as the same numbers', function (): void {
    $stats = app(PlatformStatsService::class);

    $subscriptions = $stats->subscriptions();
    $funnel = $stats->onboardingFunnel();

    $subscriptionsKey = WidgetCache::key(PlatformStatsService::SUBSCRIPTIONS_WIDGET, 'all');
    $funnelKey = WidgetCache::key(PlatformStatsService::FUNNEL_WIDGET, 'all');

    expect($stats->subscriptions())->toBe($subscriptions)
        ->and($stats->onboardingFunnel())->toBe($funnel)
        ->and(PureCache::store()->get($subscriptionsKey))->toBe($subscriptions)
        ->and(PureCache::store()->get($funnelKey))->toBe($funnel)
        ->and(cacheEntryIsPlain(PureCache::store()->get($subscriptionsKey)))->toBeTrue()
        ->and(cacheEntryIsPlain(PureCache::store()->get($funnelKey)))->toBeTrue();
});
