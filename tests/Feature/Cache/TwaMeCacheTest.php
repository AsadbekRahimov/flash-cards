<?php

declare(strict_types=1);

/*
 * The profile snapshot of `GET /me` (docs/19 K9, R12i): the plan, the groups, the renewal / lapsed blocks and the active
 * course of the settings come from one entry of the pure cache, keyed by the learner's version (`entitlement:ver`), the
 * locale and the active course. Every write that changes one of them moves the version (or the key), so the old snapshot
 * is never read again: "записал -> старое не отдаётся" for each path. A failing `redis-cache` leaves the answer right.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\MeProfileCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\PureCacheStandIn;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function meCacheHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function meCacheGet(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(meCacheHeaders($learner))->getJson('/api/twa/me')->assertOk();
}

function meCacheKey(Learner $learner, string $locale = 'ru'): string
{
    return MeProfileCache::key($learner->refresh(), $locale, EntitlementResolver::version($learner->id));
}

function meCacheCourse(string $slug, array $title): Course
{
    return Course::factory()->published()->create(['slug' => $slug, 'title' => $title]);
}

it('serves the second /me from the snapshot: the learner and one statement of counts, the same answer', function (): void {
    $course = meCacheCourse('english-a1', ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']);
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $course->id, 'interface_locale' => 'ru']);
    $group = TelegramGroup::factory()->create(['title' => 'Group 7B']);
    app(GroupMembershipService::class)->join($learner, $group, 'test');
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme']);
    app(EntitlementResolver::class)->forget($learner);

    $first = meCacheGet($this, $learner)->json();
    expect(PureCache::store()->has(meCacheKey($learner)))->toBeTrue();

    $queries = QueryLog::during(fn () => expect(meCacheGet($this, $learner)->json())->toBe($first));

    expect(count($queries))->toBeLessThanOrEqual(2)
        ->and(QueryLog::matching($queries, '/from ["`]learners["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/from ["`](telegram_groups|subscriptions|courses)["`]/'))->toBe([])
        ->and($first['groups'])->toBe([['id' => $group->id, 'title' => 'Group 7B']])
        ->and($first['entitlement']['plan'])->toBe('pro')
        ->and($first['entitlement']['renewal']['provider'])->toBe('payme')
        ->and($first['settings']['active_course'])->toBe(['slug' => 'english-a1', 'title' => 'Английский A1']);
});

it('shows a subscription granted after the snapshot at once: the grant moves the version', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.renewal', null);
    $before = meCacheKey($learner);

    app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');

    expect(meCacheKey($learner))->not->toBe($before)
        ->and(PureCache::store()->has($before))->toBeTrue(); // the old entry ages out; nobody reads it
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro')->assertJsonPath('entitlement.renewal.provider', 'manual');
});

it('shows a subscription ended by hand at once', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $subscription = app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro');

    app(SubscriptionService::class)->endNow($subscription, 'refund', User::factory()->create());

    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.renewal', null)
        ->assertJsonPath('entitlement.lapsed', null); // ended by hand is not a lapse (docs/13)
});

it('shows a lapsed subscription after the expiry run, and recomputes a snapshot whose Pro ran out even without a bump', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addHour()]);
    app(EntitlementResolver::class)->forget($learner);
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro');
    $key = meCacheKey($learner);

    // The scheduler is late: the plan is Free by the clock, the snapshot says so without any write.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
    Carbon::setTestNow(Carbon::now()->addHours(2));
    expect(meCacheKey($learner))->toBe($key);
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free');

    // The scheduler marks it expired: the version moves and the banner appears.
    expect(app(SubscriptionService::class)->expireDue(CarbonImmutable::now()))->toBe(1);
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.lapsed.provider', 'payme');
});

it('shows the renewal reminder the moment its marker is written', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(3)->subHours(2)]);
    app(EntitlementResolver::class)->forget($learner);
    meCacheGet($this, $learner)->assertJsonPath('entitlement.renewal.reminder_sent', false);

    expect(app(SubscriptionService::class)->remindExpiring(CarbonImmutable::now()))->toBe(1);

    meCacheGet($this, $learner)->assertJsonPath('entitlement.renewal.reminder_sent', true);
});

it('shows a group joined or left after the snapshot at once', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $group = TelegramGroup::factory()->create(['title' => 'Group 7B']);
    meCacheGet($this, $learner)->assertJsonPath('groups', []);

    $service = app(GroupMembershipService::class);
    $service->join($learner, $group, 'test');
    meCacheGet($this, $learner)->assertJsonPath('groups', [['id' => $group->id, 'title' => 'Group 7B']]);

    $service->leave($learner, $group);
    meCacheGet($this, $learner)->assertJsonPath('groups', []);
});

it('shows the seat of a center at once: the membership moves the version', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $organization = Organization::factory()->create();
    Subscription::factory()->forOrganization($organization)->create(['seats_limit' => 5]);
    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free');

    app(OrganizationMembershipService::class)->joinByAdmin($learner, $organization, User::factory()->create());

    meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro')->assertJsonPath('entitlement.source', 'sponsor:organization:'.$organization->id);
});

it('keys the snapshot by the active course and the locale: a new course or language is a new entry', function (): void {
    $a = meCacheCourse('english-a1', ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']);
    $b = meCacheCourse('english-a2', ['ru' => 'Английский A2', 'uz' => 'Ingliz tili A2']);
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $a->id, 'interface_locale' => 'ru']);
    meCacheGet($this, $learner)->assertJsonPath('settings.active_course', ['slug' => 'english-a1', 'title' => 'Английский A1']);
    $keyA = meCacheKey($learner);

    $this->withHeaders(meCacheHeaders($learner))->postJson('/api/twa/me/course', ['slug' => 'english-a2'])->assertOk();
    expect(meCacheKey($learner))->not->toBe($keyA)->and(meCacheKey($learner))->toContain(':c'.$b->id.':');
    meCacheGet($this, $learner)->assertJsonPath('settings.active_course', ['slug' => 'english-a2', 'title' => 'Английский A2']);

    $this->withHeaders(meCacheHeaders($learner))->patchJson('/api/twa/me/settings', ['interface_locale' => 'uz'])->assertOk();
    expect(meCacheKey($learner, 'uz'))->toContain(':uz:');
    meCacheGet($this, $learner)->assertJsonPath('settings.active_course', ['slug' => 'english-a2', 'title' => 'Ingliz tili A2']);
});

it('keeps /me right when the pure cache is gone: every call computes from the database', function (): void {
    $markers = PureCacheStandIn::markerDirectory();
    PureCacheStandIn::pointPureCacheAt('127.0.0.1', PureCacheStandIn::DEAD_PORT, $markers);

    try {
        $course = meCacheCourse('english-a1', ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1']);
        $learner = Learner::factory()->onboarded()->create(['active_course_id' => $course->id]);
        $group = TelegramGroup::factory()->create(['title' => 'Group 7B']);
        app(GroupMembershipService::class)->join($learner, $group, 'test');

        meCacheGet($this, $learner)->assertJsonPath('groups', [['id' => $group->id, 'title' => 'Group 7B']])->assertJsonPath('entitlement.plan', 'free');
        app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');
        meCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro')->assertJsonPath('settings.active_course.slug', 'english-a1');
    } finally {
        PureCacheStandIn::removeDirectory($markers);
    }
})->skip(! extension_loaded('redis'), 'phpredis is required to talk to a (dead) Redis');
