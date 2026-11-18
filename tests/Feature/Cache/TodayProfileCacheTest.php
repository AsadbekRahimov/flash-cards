<?php

declare(strict_types=1);

/*
 * The two entries of `GET /today` in the pure cache (docs/19 K10): the Today snapshot `twa:today:v1:{learner}:n{version}` (the lapse
 * banner and the "in a group" fact, keyed by the learner's version `entitlement:ver`) and the course row `catalog:course:row:v1:{course}:n{version}`
 * (keyed by the course's data version). Every write that changes one of them moves the version, so the old entry is never read again:
 * "записал -> старое не отдаётся" for each path. A failing `redis-cache` leaves the answer right.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\TodayProfileCache;
use App\Models\ClassCaravan;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\ClassKit;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\PureCacheStandIn;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    Carbon::setTestNow('2026-11-05 10:00:00');
    config(['game.access' => 'all', 'readiness.enabled' => true, 'learning.hard_round.enabled' => true]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

function todayCacheGet(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->getJson('/api/twa/today')->assertOk();
}

function todaySnapshotKey(Learner $learner): string
{
    return TodayProfileCache::key($learner, EntitlementResolver::version($learner->id));
}

// --- the snapshot: the lapse banner ------------------------------------------------------------------------------

it('keeps the lapse banner in the snapshot: arrays only, under the learner\'s version, and serves it without a read of subscriptions', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'ended_at' => CarbonImmutable::now()->subDays(3)]);

    $first = todayCacheGet($this, $learner)->json('entitlement.lapsed');
    $key = todaySnapshotKey($learner);

    expect($first['provider'])->toBe('payme')
        ->and($key)->toStartWith('twa:today:v1:'.$learner->id.':n')
        ->and(PureCache::store()->get($key))->toBe(['lapsed' => $first, 'groups' => false]);

    $queries = QueryLog::during(fn () => todayCacheGet($this, $learner)->assertJsonPath('entitlement.lapsed', $first));

    expect(QueryLog::matching($queries, '/from ["`](subscriptions|group_members|telegram_groups|class_caravans)["`]/'))->toBe([]);
});

it('shows a subscription granted after the snapshot at once: the grant moves the version, the banner goes', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'ended_at' => CarbonImmutable::now()->subDays(3)]);
    todayCacheGet($this, $learner)->assertJsonPath('entitlement.lapsed.provider', 'payme')->assertJsonPath('entitlement.plan', 'free');
    $before = todaySnapshotKey($learner);

    app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');

    expect(todaySnapshotKey($learner))->not->toBe($before)
        ->and(PureCache::store()->has($before))->toBeTrue(); // the old entry ages out; nobody reads it
    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro')->assertJsonPath('entitlement.lapsed', null);
});

it('shows the lapse the moment the expiry run marks the subscription expired', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addHour()]);
    app(EntitlementResolver::class)->forget($learner);
    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro')->assertJsonPath('entitlement.lapsed', null);

    // The scheduler is late: Free by the clock, no banner yet (the subscription is not `expired` in the database either).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
    Carbon::setTestNow(Carbon::now()->addHours(2));
    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.lapsed', null);

    expect(app(SubscriptionService::class)->expireDue(CarbonImmutable::now()))->toBe(1);

    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.lapsed.provider', 'payme');
});

it('shows a subscription ended by hand as no lapse, at once', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    $subscription = app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');
    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'pro');

    app(SubscriptionService::class)->endNow($subscription, 'refund', User::factory()->create());

    todayCacheGet($this, $learner)->assertJsonPath('entitlement.plan', 'free')->assertJsonPath('entitlement.lapsed', null);
});

// --- the snapshot: the groups and the card of the class caravan -------------------------------------------------------

it('reads no class caravan for a learner in no group, and shows the card at once after joining the group and none after leaving it', function (): void {
    $w = ClassKit::world(1);
    ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id, 'organization_id' => $w['organization']->id, 'course_id' => $w['course']->id, 'target_stars' => 18, 'stars_total' => 7]);
    $member = $w['learners'][0];
    $stranger = Learner::factory()->onboarded()->create();

    // The member: the card, read from `class_caravans` on every call (the stars change with every run of the class).
    todayCacheGet($this, $member);
    $queries = QueryLog::during(fn () => todayCacheGet($this, $member)->assertJsonPath('game.class_caravan.stars_total', 7));
    expect(QueryLog::matching($queries, '/from ["`]class_caravans["`]/'))->toHaveCount(1)
        ->and(PureCache::store()->get(todaySnapshotKey($member)))->toBe(['lapsed' => null, 'groups' => true]);

    // The stranger: no card, and no read of the class tables once the snapshot says "in no group".
    todayCacheGet($this, $stranger)->assertJsonPath('game.class_caravan', null);
    $queries = QueryLog::during(fn () => todayCacheGet($this, $stranger)->assertJsonPath('game.class_caravan', null));
    expect(QueryLog::matching($queries, '/from ["`](class_caravans|group_members|telegram_groups)["`]/'))->toBe([])
        ->and(PureCache::store()->get(todaySnapshotKey($stranger)))->toBe(['lapsed' => null, 'groups' => false]);

    // Joins the class: the join moves the version, the next /today reads the card.
    $before = todaySnapshotKey($stranger);
    app(GroupMembershipService::class)->join($stranger, $w['group'], 'test');
    expect(todaySnapshotKey($stranger))->not->toBe($before);
    $queries = QueryLog::during(fn () => todayCacheGet($this, $stranger)->assertJsonPath('game.class_caravan.stars_total', 7));
    expect(QueryLog::matching($queries, '/from ["`]class_caravans["`]/'))->toHaveCount(1);

    // Leaves: the card is gone at once, and the class tables are not read any more.
    app(GroupMembershipService::class)->leave($stranger, $w['group']);
    todayCacheGet($this, $stranger)->assertJsonPath('game.class_caravan', null);
    $queries = QueryLog::during(fn () => todayCacheGet($this, $stranger)->assertJsonPath('game.class_caravan', null));
    expect(QueryLog::matching($queries, '/from ["`](class_caravans|group_members|telegram_groups)["`]/'))->toBe([]);
});

it('drops the card at once when the group is deactivated: the group\'s status moves the versions of its members', function (): void {
    $w = ClassKit::world(1);
    ClassCaravan::factory()->create(['telegram_group_id' => $w['group']->id, 'organization_id' => $w['organization']->id, 'course_id' => $w['course']->id, 'target_stars' => 18, 'stars_total' => 7]);
    $member = $w['learners'][0];
    todayCacheGet($this, $member)->assertJsonPath('game.class_caravan.stars_total', 7);

    $w['group']->update(['status' => 'disabled']); // what MyChatMemberHandler writes when the bot is removed from the chat

    todayCacheGet($this, $member)->assertJsonPath('game.class_caravan', null);
    $queries = QueryLog::during(fn () => todayCacheGet($this, $member)->assertJsonPath('game.class_caravan', null));
    expect(QueryLog::matching($queries, '/from ["`]class_caravans["`]/'))->toBe([]);
});

it('counts the memberships in the statement of scalars on a cold snapshot, not in a statement of their own', function (): void {
    $w = ClassKit::world(1);
    $member = $w['learners'][0];

    $queries = QueryLog::during(fn () => todayCacheGet($this, $member));

    expect(QueryLog::matching($queries, '/^select \(select count\(\*\) from ["`]word_repetitions["`].*\(select count\(\*\) from ["`]group_members["`] as ["`]gm["`] inner join ["`]telegram_groups["`] as ["`]tg["`] on ["`]tg["`].["`]id["`] = ["`]gm["`].["`]telegram_group_id["`] and ["`]tg["`].["`]status["`] = \? where ["`]gm["`].["`]learner_id["`] = \? and ["`]gm["`].["`]status["`] = \?\) as ["`]groups["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select [^(]*from ["`]group_members["`]/'))->toBe([]) // no statement of its own
        ->and(PureCache::store()->get(todaySnapshotKey($member)))->toBe(['lapsed' => null, 'groups' => true]);
});

it('ignores a snapshot that is not of the expected shape and computes afresh', function (): void {
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'ended_at' => CarbonImmutable::now()->subDays(3)]);
    PureCache::store()->put(todaySnapshotKey($learner), ['lapsed' => 'yes', 'groups' => '1'], 600);

    todayCacheGet($this, $learner)->assertJsonPath('entitlement.lapsed.provider', 'payme');

    expect(PureCache::store()->get(todaySnapshotKey($learner))['groups'])->toBeFalse();
});

// --- the course row --------------------------------------------------------------------------------------------------

/** @return array{course: Course, learner: Learner} */
function todayCacheCourse(): array
{
    ['course' => $course] = Fx::course([1 => [1 => 3]], ['slug' => 'silk', 'title' => ['ru' => 'Шёлк', 'uz' => 'Ipak'], 'level' => 'A1']);
    $learner = Learner::factory()->onboarded()->create(['active_course_id' => $course->id, 'interface_locale' => 'ru']);

    return ['course' => $course, 'learner' => $learner];
}

it('keeps the row of the course as the driver returned it, under the course\'s data version, shared by its learners', function (): void {
    ['course' => $course, 'learner' => $learner] = todayCacheCourse();
    $other = Learner::factory()->onboarded()->create(['active_course_id' => $course->id]);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.title', 'Шёлк')->assertJsonPath('active_course.progress.total', 3);
    $key = CourseCatalogService::courseRowKey($course->id);

    expect($key)->toStartWith('catalog:course:row:v1:'.$course->id.':n')
        ->and(PureCache::store()->get($key))->toBeArray()->toHaveKeys(['id', 'slug', 'status', 'visibility', 'owner_type', 'words_count', 'title'])
        ->and((int) PureCache::store()->get($key)['id'])->toBe($course->id);

    $queries = QueryLog::during(fn () => todayCacheGet($this, $other)->assertJsonPath('active_course.title', 'Шёлк'));
    expect(QueryLog::matching($queries, '/from ["`]courses["`]/'))->toBe([]);
});

it('shows a renamed course at once: any saved change of the course moves its version', function (): void {
    ['course' => $course, 'learner' => $learner] = todayCacheCourse();
    todayCacheGet($this, $learner)->assertJsonPath('active_course.title', 'Шёлк');
    $before = CourseCatalogService::courseRowKey($course->id);

    Course::query()->findOrFail($course->id)->update(['title' => ['ru' => 'Шёлковый путь', 'uz' => 'Ipak yoli']]);

    expect(CourseCatalogService::courseRowKey($course->id))->not->toBe($before);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.title', 'Шёлковый путь');
});

it('shows the new word total at once after a recount, and the level label after an edit of meta', function (): void {
    ['course' => $course, 'learner' => $learner] = todayCacheCourse();
    todayCacheGet($this, $learner)->assertJsonPath('active_course.progress.total', 3)->assertJsonPath('active_course.readiness.level_label', 'A1');

    Word::factory()->count(2)->for(Lesson::query()->firstOrFail())->create();
    app(CoursePublisher::class)->recount($course);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.progress.total', 5);

    Course::query()->findOrFail($course->id)->update(['meta' => ['level_label' => 'A1–A2']]);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.readiness.level_label', 'A1–A2');
});

it('treats a course taken off publication or made private as absent at once', function (): void {
    ['course' => $course, 'learner' => $learner] = todayCacheCourse();
    todayCacheGet($this, $learner)->assertJsonPath('active_course.id', $course->id);

    Course::query()->findOrFail($course->id)->update(['status' => 'draft']);
    todayCacheGet($this, $learner)->assertJsonPath('active_course', null);

    Course::query()->findOrFail($course->id)->update(['status' => 'published', 'visibility' => 'private']);
    todayCacheGet($this, $learner)->assertJsonPath('active_course', null);

    Course::query()->findOrFail($course->id)->update(['visibility' => 'unlisted']);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.id', $course->id);
});

it('follows a change of the active course at once: another course is another key', function (): void {
    ['course' => $course, 'learner' => $learner] = todayCacheCourse();
    ['course' => $other] = Fx::course([1 => [1 => 4]], ['slug' => 'spice', 'title' => ['ru' => 'Пряности', 'uz' => 'Ziravorlar']]);
    todayCacheGet($this, $learner)->assertJsonPath('active_course.slug', 'silk');

    $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->postJson('/api/twa/me/course', ['slug' => 'spice'])->assertOk();

    todayCacheGet($this, $learner)->assertJsonPath('active_course.slug', 'spice')->assertJsonPath('active_course.progress.total', 4);
    expect(PureCache::store()->has(CourseCatalogService::courseRowKey($course->id)))->toBeTrue()
        ->and(PureCache::store()->has(CourseCatalogService::courseRowKey($other->id)))->toBeTrue();
});

it('does not cache a course that does not exist', function (): void {
    expect(app(CourseCatalogService::class)->cachedCourse(987654))->toBeNull()
        ->and(PureCache::store()->has(CourseCatalogService::courseRowKey(987654)))->toBeFalse();
});

// --- without the pure cache ---------------------------------------------------------------------------------------------

it('keeps /today right when the pure cache is gone: every call computes from the database', function (): void {
    $markers = PureCacheStandIn::markerDirectory();
    PureCacheStandIn::pointPureCacheAt('127.0.0.1', PureCacheStandIn::DEAD_PORT, $markers);

    try {
        ['course' => $course, 'learner' => $learner] = todayCacheCourse();
        Subscription::factory()->expired()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'ended_at' => CarbonImmutable::now()->subDays(3)]);

        todayCacheGet($this, $learner)->assertJsonPath('active_course.title', 'Шёлк')->assertJsonPath('entitlement.lapsed.provider', 'payme');
        Course::query()->findOrFail($course->id)->update(['title' => ['ru' => 'Шёлковый путь', 'uz' => 'Ipak yoli']]);
        app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(30), User::factory()->create(), 'test');
        todayCacheGet($this, $learner)->assertJsonPath('active_course.title', 'Шёлковый путь')->assertJsonPath('entitlement.lapsed', null)->assertJsonPath('entitlement.plan', 'pro');
    } finally {
        PureCacheStandIn::removeDirectory($markers);
    }
})->skip(! extension_loaded('redis'), 'phpredis is required to talk to a (dead) Redis');
