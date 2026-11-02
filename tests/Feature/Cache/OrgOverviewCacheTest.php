<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Domain\Organizations\Services\OrgOverviewService;
use App\Filament\Org\Widgets\OrgActivityWidget;
use App\Filament\Org\Widgets\OrgSeatsWidget;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The overview of the /org dashboard from the pure cache (docs/19 K2): one entry per center and kind of viewer, 90 s, dropped
 * at once by the membership events, a group moving in or out of the center and a change of its subscription. The id of the
 * organization is in the key, so a center never reads the numbers of another.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.pure_store' => 'array']);
    $this->now = CarbonImmutable::now();

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a', 'timezone' => 'Asia/Tashkent']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b', 'timezone' => 'Asia/Tashkent']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => $this->now->addDays(10), 'tier' => 'tutor', 'seats_limit' => 30]);
    Subscription::factory()->forOrganization($this->b)->create(['current_period_ends_at' => $this->now->addDays(20), 'tier' => 'center_s', 'seats_limit' => 100]);

    $this->ownerA = orgCacheStaff($this->a, OrganizationRole::Owner);
    $this->ownerB = orgCacheStaff($this->b, OrganizationRole::Owner);
    $this->teacherA = orgCacheStaff($this->a, OrganizationRole::Teacher);

    $this->groupA1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id]);
    $this->groupA2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id]);
    $this->groupB = TelegramGroup::factory()->create(['organization_id' => $this->b->id]);
    $this->teacherA->telegramGroups()->attach($this->groupA1->id, ['is_primary' => true]);

    $this->l1 = Learner::factory()->memberOf($this->groupA1)->seatedIn($this->a)->create();
    $this->l2 = Learner::factory()->memberOf($this->groupA2)->seatedIn($this->a)->create();
    $this->l3 = Learner::factory()->seatedIn($this->a, 'waitlisted')->create();
    $this->lb = Learner::factory()->memberOf($this->groupB)->seatedIn($this->b)->create();

    $today = $this->now->setTimezone('Asia/Tashkent')->toDateString();
    LearnerDailyStats::factory()->create(['learner_id' => $this->l1->id, 'date' => $today, 'reviews_count' => 10, 'new_words_count' => 3]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->l2->id, 'date' => $today, 'reviews_count' => 4, 'new_words_count' => 0]);
    LearnerDailyStats::factory()->create(['learner_id' => $this->lb->id, 'date' => $today, 'reviews_count' => 7, 'new_words_count' => 1]);
});

function orgCacheStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** Puts the test into the cabinet of $tenant as $user, as the panel middleware would. */
function orgCacheEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

function orgOverview(): OrgOverviewService
{
    return app(OrgOverviewService::class);
}

it('returns from the cache the very figures summary() computes, for an owner and for a teacher', function (): void {
    foreach ([$this->ownerA, $this->teacherA] as $user) {
        $fresh = orgOverview()->summary($user, $this->a)->toArray();

        expect(orgOverview()->cachedSummary($user, $this->a)->toArray())->toBe($fresh)
            ->and(orgOverview()->cachedSummary($user, $this->a)->toArray())->toBe($fresh, 'and the second time, from the cache');
    }

    $owner = orgOverview()->cachedSummary($this->ownerA, $this->a);
    expect($owner->seats->seated)->toBe(2)
        ->and($owner->seats->waitlisted)->toBe(1)
        ->and($owner->reviews7d)->toBe(14)
        ->and($owner->subscriptionTier)->toBe('tutor')
        ->and($owner->subscriptionEndsAt?->toIso8601String())->toBe(orgOverview()->summary($this->ownerA, $this->a)->subscriptionEndsAt?->toIso8601String());

    $teacher = orgOverview()->cachedSummary($this->teacherA, $this->a);
    expect($teacher->groups)->toBe(1)
        ->and($teacher->reviews7d)->toBe(10)
        ->and($teacher->subscriptionTier)->toBeNull();
});

it('reads a cached summary with the lookup of the role only, not with the aggregates', function (): void {
    orgOverview()->cachedSummary($this->ownerA, $this->a);
    orgOverview()->cachedSummary($this->teacherA, $this->a);

    $owner = QueryLog::during(fn () => orgOverview()->cachedSummary($this->ownerA, $this->a));
    $teacher = QueryLog::during(fn () => orgOverview()->cachedSummary($this->teacherA, $this->a));

    // The owner: the role. The teacher: the role (asked again for the ids of their groups) and those ids, which are their key.
    // Nothing of learner_daily_stats, organization_members or subscriptions - the 14 statements of the real computation.
    expect($owner)->toHaveCount(1)
        ->and(QueryLog::matching($owner, '/organization_users/'))->toHaveCount(1)
        ->and($teacher)->toHaveCount(3)
        ->and(QueryLog::matching($teacher, '/learner_daily_stats|organization_members|subscriptions/'))->toBe([]);
});

it('keeps one entry per center and viewer, the organization id in the key, and arrays only', function (): void {
    orgOverview()->cachedSummary($this->ownerA, $this->a);
    orgOverview()->cachedSummary($this->ownerB, $this->b);
    orgOverview()->cachedSummary($this->teacherA, $this->a);

    $ownerA = OrgOverviewService::cacheKey($this->a->id, 'owner');
    $ownerB = OrgOverviewService::cacheKey($this->b->id, 'owner');

    expect($ownerA)->toBe("org:{$this->a->id}:overview:v1:n0:owner")
        ->and($ownerB)->not->toBe($ownerA)
        ->and(PureCache::store()->has($ownerA))->toBeTrue()
        ->and(PureCache::store()->has($ownerB))->toBeTrue()
        ->and(PureCache::store()->get($ownerA))->toBeArray()
        ->and(OrgOverviewService::versionKey($this->a->id))->toBe("org:{$this->a->id}:overview:ver");
});

it('never lets a center read the numbers of another: not by a shared user, nor by the other\'s warm cache', function (): void {
    $viaA = orgOverview()->cachedSummary($this->ownerA, $this->a);
    $viaB = orgOverview()->cachedSummary($this->ownerB, $this->b);

    expect($viaA->reviews7d)->toBe(14)->and($viaA->seats->seated)->toBe(2)
        ->and($viaB->reviews7d)->toBe(7)->and($viaB->seats->seated)->toBe(1)
        ->and($viaB->subscriptionTier)->toBe('center_s');

    // One person who owns both centers gets each center's own numbers, in either order and from a warm cache.
    OrganizationUser::factory()->create(['organization_id' => $this->b->id, 'user_id' => $this->ownerA->id, 'role' => OrganizationRole::Owner->value]);
    foreach ([$this->a, $this->b, $this->a, $this->b] as $organization) {
        $summary = orgOverview()->cachedSummary($this->ownerA, $organization);

        expect($summary->reviews7d)->toBe($organization->is($this->a) ? 14 : 7);
    }

    // A user of A asking about B is not staff there: the empty answer, and nothing of B in A's entry.
    $stranger = orgOverview()->cachedSummary($this->ownerB, $this->a);
    expect($stranger->activeMembers)->toBe(0)->and($stranger->reviews7d)->toBe(0)->and($stranger->subscriptionTier)->toBeNull();
    expect(PureCache::store()->has(OrgOverviewService::cacheKey($this->a->id, 'owner')))->toBeTrue();
});

it('does not cache anything for somebody who is not staff of the center', function (): void {
    $outsider = User::factory()->create();

    orgOverview()->cachedSummary($outsider, $this->a);

    expect(PureCache::store()->has(OrgOverviewService::cacheKey($this->a->id, 'owner')))->toBeFalse();
});

it('drops the summary when a member joins, leaves or gets a seat', function (): void {
    $service = app(OrganizationMembershipService::class);
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->activeMembers)->toBe(3);

    // A join through the service: the event it raises after commit drops the entry, nothing waits for the TTL.
    $newcomer = Learner::factory()->create();
    $service->joinByAdmin($newcomer, $this->a, $this->ownerA);
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->activeMembers)->toBe(4)
        ->and(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->seated)->toBe(3);

    // The freed seat goes to the first of the waiting (the learner who waited): seated stays, the waiting list empties.
    $service->leave($newcomer, $this->a, 'removed', $this->ownerA);
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->activeMembers)->toBe(3)
        ->and(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->seated)->toBe(3)
        ->and(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->waitlisted)->toBe(0);

    // The center B kept its own entry: events carry the id of the center.
    expect(PureCache::store()->has(OrgOverviewService::cacheKey($this->b->id, 'owner')))->toBeFalse('B was never read, nothing to keep');
    orgOverview()->cachedSummary($this->ownerB, $this->b);
    $service->joinByAdmin(Learner::factory()->create(), $this->a, $this->ownerA);
    expect(PureCache::store()->has(OrgOverviewService::cacheKey($this->b->id, 'owner')))->toBeTrue('B untouched by a join in A');
});

it('drops the summary on each of the three membership events by themselves', function (string $kind): void {
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->seated)->toBe(2);
    Learner::factory()->seatedIn($this->a)->create(); // written without the service: only the event says so
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->seated)->toBe(2, 'still the cached figure');

    $event = match ($kind) {
        'joined' => new OrganizationMemberJoined($this->a->id, $this->l1->id, 1, 'invite', 'seated'),
        'left' => new OrganizationMemberLeft($this->a->id, $this->l1->id, 1, 'left', true, null),
        'seated' => new OrganizationMemberSeated($this->a->id, $this->l1->id, 1),
    };
    event($event);

    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->seats->seated)->toBe(3);
})->with(['joined', 'left', 'seated']);

it('drops the summary of both centers when a group moves from one to the other', function (): void {
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->groups)->toBe(2)
        ->and(orgOverview()->cachedSummary($this->ownerB, $this->b)->groups)->toBe(1);

    $this->groupA2->update(['organization_id' => $this->b->id]);

    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->groups)->toBe(1)
        ->and(orgOverview()->cachedSummary($this->ownerB, $this->b)->groups)->toBe(2);

    // Unlinked altogether, and deleted.
    $this->groupA1->update(['organization_id' => null]);
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->groups)->toBe(0);

    $this->groupB->delete();
    expect(orgOverview()->cachedSummary($this->ownerB, $this->b)->groups)->toBe(1);
});

it('drops the summary when the subscription of the center changes, so an owner who has just paid sees it', function (): void {
    $before = orgOverview()->cachedSummary($this->ownerA, $this->a);
    expect($before->subscriptionTier)->toBe('tutor');

    $admin = User::factory()->create(['is_active' => true]);
    app(SubscriptionService::class)->grantManual($this->b, Plan::Pro, $this->now->addDays(60), $admin, 'test', tier: 'center_s');
    // B changed, A did not: A's entry is still there.
    expect(PureCache::store()->has(OrgOverviewService::cacheKey($this->a->id, 'owner')))->toBeTrue();

    $live = Subscription::query()->where('subscriber_type', 'organization')->where('subscriber_id', $this->a->id)->firstOrFail();
    app(SubscriptionService::class)->endNow($live, 'test', $admin);

    $after = orgOverview()->cachedSummary($this->ownerA, $this->a);
    expect($after->seats->sponsoring)->toBeFalse()
        ->and($after->subscriptionTier)->toBeNull();
});

it('lets only the activity numbers wait for the end of the TTL', function (): void {
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->reviews7d)->toBe(14);

    LearnerDailyStats::factory()->create(['learner_id' => $this->l3->id, 'date' => $this->now->setTimezone('Asia/Tashkent')->subDay()->toDateString(), 'reviews_count' => 6, 'new_words_count' => 0]);
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->reviews7d)->toBe(14, 'no event announces a learner\'s answer');

    $this->travel(OrgOverviewService::CACHE_TTL + 1)->seconds();
    expect(orgOverview()->cachedSummary($this->ownerA, $this->a)->reviews7d)->toBe(20);
});

it('keys a teacher by their groups, so a group they lose is gone from their numbers at once', function (): void {
    expect(orgOverview()->cachedSummary($this->teacherA, $this->a)->groups)->toBe(1);

    $this->teacherA->telegramGroups()->attach($this->groupA2->id);
    expect(orgOverview()->cachedSummary($this->teacherA, $this->a)->groups)->toBe(2);

    $this->teacherA->telegramGroups()->detach([$this->groupA1->id, $this->groupA2->id]);
    expect(orgOverview()->cachedSummary($this->teacherA, $this->a)->groups)->toBe(0);
});

it('serves both widgets of the dashboard from one entry: the second open runs no aggregate query', function (): void {
    orgCacheEnter($this->ownerA, $this->a);

    Livewire::test(OrgSeatsWidget::class)->assertOk()->assertSee('2 / 30')->assertSee('tutor');
    Livewire::test(OrgActivityWidget::class)->assertOk()->assertSee('14');

    $queries = QueryLog::during(function (): void {
        Livewire::test(OrgSeatsWidget::class)->assertOk()->assertSee('2 / 30');
        Livewire::test(OrgActivityWidget::class)->assertOk();
    });

    expect(QueryLog::matching($queries, '/learner_daily_stats|organization_members|subscriptions|telegram_groups|group_members/'))->toBe([]);
});

it('shows the owner of center A the numbers of A only, even when center B\'s dashboard is warm', function (): void {
    orgCacheEnter($this->ownerB, $this->b);
    Livewire::test(OrgSeatsWidget::class)->assertOk()->assertSee('1 / 100');

    orgCacheEnter($this->ownerA, $this->a);
    Livewire::test(OrgSeatsWidget::class)->assertOk()->assertSee('2 / 30')->assertDontSee('1 / 100');
});

it('does not poll and loads lazily', function (): void {
    orgCacheEnter($this->ownerA, $this->a);

    foreach ([OrgSeatsWidget::class, OrgActivityWidget::class] as $widget) {
        expect($widget::isLazyLoaded())->toBeTrue();

        Livewire::test($widget)->assertOk()->assertDontSeeHtml('wire:poll');
    }
});
