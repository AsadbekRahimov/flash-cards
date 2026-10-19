<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
    config()->set('billing.cache_ttl', 300);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{learner: Learner, group: TelegramGroup, organization: Organization}
 */
function entitlementSponsoredLearner(?Organization $organization = null): array
{
    $organization ??= Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $organization->id]);
    $learner = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);

    return ['learner' => $learner, 'group' => $group, 'organization' => $organization];
}

function entitlementSubscription(Learner|Organization $subscriber, array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'subscriber_type' => $subscriber instanceof Learner ? 'learner' : 'organization',
        'subscriber_id' => $subscriber->id,
        ...$attributes,
    ]);
}

it('resolves Free with the configured limit and features when nothing is live', function (): void {
    $learner = Learner::factory()->create();

    $e = app(EntitlementResolver::class)->for($learner);

    expect($e->plan)->toBe(Plan::Free)
        ->and($e->source)->toBe('free')
        ->and($e->until)->toBeNull()
        ->and($e->subscriptionId)->toBeNull()
        ->and($e->newWordsPerDay)->toBe(20)
        ->and($e->isUnlimited())->toBeFalse()
        ->and($e->can('training.daily'))->toBeTrue()
        ->and($e->can('stats.detailed'))->toBeFalse();
});

it('reads the Free limit from config/billing.php only', function (): void {
    config()->set('billing.plans.free.limits.new_words_per_day', 7);

    expect(app(EntitlementResolver::class)->for(Learner::factory()->create())->newWordsPerDay)->toBe(7);
});

it('resolves Pro from a live personal subscription with its end date', function (): void {
    $learner = Learner::factory()->create();
    $sub = entitlementSubscription($learner, ['current_period_ends_at' => CarbonImmutable::now()->addDays(10)]);

    $e = app(EntitlementResolver::class)->for($learner);

    expect($e->plan)->toBe(Plan::Pro)
        ->and($e->source)->toBe(Entitlement::SOURCE_SUBSCRIPTION)
        ->and($e->subscriptionId)->toBe($sub->id)
        ->and($e->until?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(10)->toIso8601String())
        ->and($e->newWordsPerDay)->toBeNull()
        ->and($e->isUnlimited())->toBeTrue()
        ->and($e->can('stats.detailed'))->toBeTrue();
});

it('resolves Pro sponsored by an organization through an active group membership', function (): void {
    ['learner' => $learner, 'organization' => $org] = entitlementSponsoredLearner();
    entitlementSubscription($org, ['current_period_ends_at' => null]);

    $e = app(EntitlementResolver::class)->for($learner);

    expect($e->plan)->toBe(Plan::Pro)
        ->and($e->source)->toBe("sponsor:organization:{$org->id}")
        ->and($e->isSponsored())->toBeTrue()
        ->and($e->until)->toBeNull();
});

it('takes the max of personal and sponsored: higher rank first, then the later end, then personal', function (): void {
    ['learner' => $learner, 'organization' => $org] = entitlementSponsoredLearner();
    $resolver = app(EntitlementResolver::class);

    // Personal ends sooner than the sponsor's -> sponsor wins (later end).
    $personal = entitlementSubscription($learner, ['current_period_ends_at' => CarbonImmutable::now()->addDays(5)]);
    $sponsor = entitlementSubscription($org, ['current_period_ends_at' => CarbonImmutable::now()->addDays(30)]);
    $e = $resolver->resolve($learner);
    expect($e->source)->toBe("sponsor:organization:{$org->id}")->and($e->subscriptionId)->toBe($sponsor->id);

    // Personal becomes perpetual -> personal wins.
    $personal->update(['current_period_ends_at' => null]);
    $e = $resolver->resolve($learner);
    expect($e->source)->toBe(Entitlement::SOURCE_SUBSCRIPTION)->and($e->until)->toBeNull();

    // Exact tie -> personal.
    $personal->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(30)]);
    expect($resolver->resolve($learner)->source)->toBe(Entitlement::SOURCE_SUBSCRIPTION);

    // Sponsor's plan outranks (simulated by demoting the personal plan's rank) -> rank beats date.
    config()->set('billing.plans.pro.rank', 1);
    $personal->update(['plan' => 'free', 'current_period_ends_at' => null]);
    expect($resolver->resolve($learner)->source)->toBe("sponsor:organization:{$org->id}");
});

it('does not sponsor a learner who left, whose group is inactive, unlinked, or whose organization is suspended', function (): void {
    ['learner' => $learner, 'group' => $group, 'organization' => $org] = entitlementSponsoredLearner();
    entitlementSubscription($org, ['current_period_ends_at' => null]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->resolve($learner)->plan)->toBe(Plan::Pro);

    GroupMember::query()->where('learner_id', $learner->id)->update(['status' => 'left', 'left_at' => now()]);
    expect($resolver->resolve($learner)->plan)->toBe(Plan::Free);

    GroupMember::query()->where('learner_id', $learner->id)->update(['status' => 'active', 'left_at' => null]);
    $group->update(['status' => 'disabled']);
    expect($resolver->resolve($learner)->plan)->toBe(Plan::Free);

    $group->update(['status' => 'active', 'organization_id' => null]);
    expect($resolver->resolve($learner)->plan)->toBe(Plan::Free);

    $group->update(['organization_id' => $org->id]);
    $org->update(['status' => 'suspended']);
    expect($resolver->resolve($learner)->plan)->toBe(Plan::Free);

    $org->update(['status' => 'active']);
    expect($resolver->resolve($learner)->plan)->toBe(Plan::Pro);
});

it('does not sponsor through another organization\'s subscription or another learner\'s subscription', function (): void {
    ['learner' => $learner] = entitlementSponsoredLearner();
    $otherOrg = Organization::factory()->create();
    entitlementSubscription($otherOrg, ['current_period_ends_at' => null]);
    entitlementSubscription(Learner::factory()->create(), ['current_period_ends_at' => null]);

    expect(app(EntitlementResolver::class)->resolve($learner)->plan)->toBe(Plan::Free);
});

it('applies the liveness rule: status, period end, grace', function (string $status, string $provider, ?int $endsInDays, ?int $graceInDays, bool $live, ?int $untilInDays): void {
    config()->set('billing.grace_days', 3);
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::now();

    entitlementSubscription($learner, [
        'status' => $status,
        'provider' => $provider,
        'current_period_ends_at' => $endsInDays === null ? null : $now->addDays($endsInDays),
        'grace_until' => $graceInDays === null ? null : $now->addDays($graceInDays),
    ]);

    $e = app(EntitlementResolver::class)->resolve($learner);

    expect($e->plan)->toBe($live ? Plan::Pro : Plan::Free);
    if ($live) {
        expect($e->until?->toIso8601String())->toBe($untilInDays === null ? null : $now->addDays($untilInDays)->toIso8601String());
    }
})->with([
    'active, perpetual' => ['active', 'manual', null, null, true, null],
    'active, ends in future' => ['active', 'manual', 10, null, true, 10],
    'trialing, ends in future' => ['trialing', 'telegram_stars', 2, null, true, 5],
    'active manual, period ended, scheduler late' => ['active', 'manual', -1, null, false, null],
    'active promo, period ended' => ['active', 'promo', -1, null, false, null],
    'active stars, period ended, inside grace' => ['active', 'telegram_stars', -1, null, true, 2],
    'active stars, period ended, grace over' => ['active', 'telegram_stars', -4, null, false, null],
    'past_due inside grace' => ['past_due', 'telegram_stars', -2, 1, true, 1],
    'past_due grace over' => ['past_due', 'telegram_stars', -5, -1, false, null],
    'past_due without grace_until' => ['past_due', 'telegram_stars', -5, null, false, null],
    'canceled' => ['canceled', 'telegram_stars', 10, null, false, null],
    'expired' => ['expired', 'manual', null, null, false, null],
]);

it('caches the entitlement per learner and recomputes only after forget()', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Free)
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();

    // A write that bypasses the services is not seen until the cache is dropped.
    entitlementSubscription($learner, ['current_period_ends_at' => null]);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $resolver->forget($learner);
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro);
});

it('invalidates by bumping the version: the old snapshot ages out, the new key starts empty', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);

    expect(EntitlementResolver::version($learner->id))->toBe(0);
    $resolver->for($learner);
    $v0Key = EntitlementResolver::cacheKey($learner->id);
    expect($v0Key)->toEndWith(':v0')->and(Cache::has($v0Key))->toBeTrue();

    $resolver->forget($learner);
    $resolver->forget($learner->id);
    $resolver->forgetMany([$learner->id, $learner->id, 999_999]);

    expect(EntitlementResolver::version($learner->id))->toBe(3)
        ->and(EntitlementResolver::version(999_999))->toBe(1)
        ->and(EntitlementResolver::cacheKey($learner->id))->toEndWith(':v3')
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and(Cache::has($v0Key))->toBeTrue(); // not deleted: nobody reads it any more, the TTL reclaims it
});

it('never caches a snapshot computed before a concurrent invalidation', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);
    $resolver->forget($learner);

    // Request A misses the cache and runs resolve(); its query has already
    // returned the pre-grant rows when request B grants Pro and invalidates.
    // A then writes its (stale) Free snapshot *after* B's forget().
    $injected = false;
    DB::listen(function () use (&$injected, $learner, $resolver): void {
        if ($injected) {
            return;
        }
        $injected = true;

        entitlementSubscription($learner, ['current_period_ends_at' => null]);
        $resolver->forget($learner);
    });

    $staleAnswer = $resolver->for($learner);
    expect($injected)->toBeTrue()
        ->and($staleAnswer->plan)->toBe(Plan::Free); // A's own answer is computed from what it read: unavoidable

    // ... but it must not shadow B's write for the next TTL: a plain forget() + put() did exactly that.
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro)
        ->and(Entitlement::fromArray(Cache::get(EntitlementResolver::cacheKey($learner->id)))->plan)->toBe(Plan::Pro);
});

it('recomputes a cached snapshot whose end has passed even without invalidation', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);
    entitlementSubscription($learner, ['current_period_ends_at' => CarbonImmutable::now()->addMinutes(2)]);

    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(3));

    // The cache entry is still there (TTL 300 s) but is no longer current.
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);
});

it('invalidates on join and leave through GroupMembershipService', function (): void {
    $org = Organization::factory()->create();
    entitlementSubscription($org, ['current_period_ends_at' => null]);
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);
    $memberships = app(GroupMembershipService::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $memberships->join($learner, $group, 'group');
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro);

    // Idempotent re-join changes nothing and keeps the cache.
    $memberships->join($learner, $group, 'group');
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();

    $memberships->leave($learner, $group);
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);

    // Leaving again (no active row) is a no-op and does not touch the cache.
    $memberships->leave($learner, $group);
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();
});

it('invalidates members when a group is linked to / unlinked from an organization or disabled', function (): void {
    $org = Organization::factory()->create();
    entitlementSubscription($org, ['current_period_ends_at' => null]);
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => null]);
    $learner = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learner->id]);
    $leftLearner = Learner::factory()->create();
    GroupMember::factory()->left()->create(['telegram_group_id' => $group->id, 'learner_id' => $leftLearner->id]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Free)
        ->and($resolver->for($leftLearner)->plan)->toBe(Plan::Free);

    $group->update(['organization_id' => $org->id]);
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($leftLearner->id)))->toBeTrue() // not a member any more: untouched
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $group->update(['title' => 'Renamed']); // irrelevant change keeps the cache
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeTrue();

    $group->update(['status' => 'disabled']);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $group->update(['status' => 'active']);
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $group->update(['organization_id' => null]);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);
});

it('invalidates members when a group is deleted', function (): void {
    ['learner' => $learner, 'group' => $group, 'organization' => $org] = entitlementSponsoredLearner();
    entitlementSubscription($org, ['current_period_ends_at' => null]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $group->delete();

    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);
});

it('forgetOrganization drops every active member of the organization\'s groups and nobody else', function (): void {
    $org = Organization::factory()->create();
    $a = entitlementSponsoredLearner($org);
    $b = entitlementSponsoredLearner($org);
    $other = entitlementSponsoredLearner();
    $resolver = app(EntitlementResolver::class);

    foreach ([$a, $b, $other] as $fixture) {
        $resolver->for($fixture['learner']);
    }

    $resolver->forgetOrganization($org);

    expect(Cache::has(EntitlementResolver::cacheKey($a['learner']->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($b['learner']->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($other['learner']->id)))->toBeTrue();
});

it('invalidates members when the organization is suspended or deleted', function (): void {
    $org = Organization::factory()->create();
    ['learner' => $learner] = entitlementSponsoredLearner($org);
    entitlementSubscription($org, ['current_period_ends_at' => null]);
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $org->update(['status' => 'suspended']);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $org->update(['status' => 'active']);
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $org->delete();
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);
});

it('round-trips through the cache array form', function (): void {
    $e = Entitlement::fromPlan(Plan::Pro, 'sponsor:organization:7', CarbonImmutable::now()->addDay(), 42);

    $restored = Entitlement::fromArray($e->toArray());

    expect($restored->plan)->toBe(Plan::Pro)
        ->and($restored->source)->toBe('sponsor:organization:7')
        ->and($restored->until?->equalTo($e->until))->toBeTrue()
        ->and($restored->subscriptionId)->toBe(42)
        ->and($restored->features)->toBe(Plan::Pro->features())
        ->and($restored->newWordsPerDay)->toBeNull()
        ->and(Entitlement::fromArray(Entitlement::free()->toArray()))->toEqual(Entitlement::free());
});

it('exposes paywall mode and a null contact when BILLING_SUPPORT_CONTACT is empty', function (): void {
    config()->set('billing.paywall.contact', '');
    expect(app(EntitlementResolver::class)->paywall())->toBe(['mode' => 'coming_soon', 'contact' => null]);

    config()->set('billing.paywall.contact', '  @lexiflow_support ');
    expect(app(EntitlementResolver::class)->paywall())->toBe(['mode' => 'coming_soon', 'contact' => '@lexiflow_support']);
});
