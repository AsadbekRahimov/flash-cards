<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\Services\GroupMembershipService;
use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Organizations\Events\OrganizationMemberLeft;
use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
 * What follows the commit of a group that moves between centers, changes its status or is deleted (TelegramGroupObserver): the
 * members follow the group, the entitlements of its members are dropped, the overview of the centers is dropped - in ONE callback, in
 * that order, each step under its own guard(). A first Redis that is gone right after the commit makes a step fail, and the failure
 * is logged: it does not throw out of the commit of whoever saved the group and it does not stop the steps after it. In particular
 * the members still move: a retry would not repeat the relink, and a learner left in the old center would keep a seat and the Pro
 * the center sponsors.
 */

uses(RefreshDatabase::class);

/**
 * The default cache store, which holds the versions of the entitlements and of the overviews, with a record of WHO moved a version on
 * (`increment`) - `forget` (a learner, by the membership service), `forgetGroup`, `forgetMany`, `overview` - and a list of the callers
 * it is down for: what a Redis that is gone looks like to one step of the observer, the other steps being served.
 */
final class GroupObserverRecordingStore extends ArrayStore
{
    /** @var list<string> */
    public array $calls = [];

    /** @param  list<string>  $down */
    public function __construct(public array $down = [])
    {
        parent::__construct();
    }

    public function increment($key, $value = 1)
    {
        $caller = $this->callerOf((string) $key);
        $this->calls[] = $caller;

        if (in_array($caller, $this->down, true)) {
            throw new RuntimeException("redis is down for {$caller}");
        }

        return parent::increment($key, $value);
    }

    private function callerOf(string $key): string
    {
        if (preg_match('/^org:\d+:overview:ver$/', $key) === 1) {
            return 'overview';
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === EntitlementResolver::class && in_array($frame['function'], ['forget', 'forgetGroup', 'forgetMany'], true)) {
                return $frame['function'];
            }
        }

        return 'other';
    }
}

/** Puts the recording store in place of the default one; the setup of the test before this call was served by the ordinary store. */
function groupObserverStore(string ...$down): GroupObserverRecordingStore
{
    $store = new GroupObserverRecordingStore(array_values($down));
    Cache::extend('group-observer-recording', fn () => Cache::repository($store));
    config(['cache.stores.group-observer-recording' => ['driver' => 'group-observer-recording'], 'cache.default' => 'group-observer-recording']);

    return $store;
}

/** The membership events raise `ForgetOrgOverview`, which moves the overview version too: they are faked, the observer's own steps are the subject. */
function groupObserverFakeMembershipEvents(): void
{
    Event::fake([OrganizationMemberJoined::class, OrganizationMemberLeft::class, OrganizationMemberSeated::class]);
}

function groupObserverMembership(Learner $learner, Organization $organization): ?string
{
    return OrganizationMember::query()->where('learner_id', $learner->id)->where('organization_id', $organization->id)->value('status');
}

/** The warning of the guard for $log, once, with the group in its context and the text of the failure that was caught. */
function groupObserverExpectLogged(string $log, TelegramGroup $group, string $error): void
{
    Log::shouldHaveReceived('warning')
        ->with($log, Mockery::on(fn (array $context): bool => $context['telegram_group_id'] === $group->id && $context['exception'] === RuntimeException::class && $context['error'] === $error))
        ->once();
}

beforeEach(function (): void {
    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => now()->addDays(10), 'tier' => 'tutor', 'seats_limit' => 30]);
    Subscription::factory()->forOrganization($this->b)->create(['current_period_ends_at' => now()->addDays(20), 'tier' => 'center_s', 'seats_limit' => 100]);

    $this->group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $this->a->id]);
    $this->learner = Learner::factory()->create();
    app(GroupMembershipService::class)->join($this->learner, $this->group, 'group');
});

it('lets the members follow a group to another center and still drops the overview when the entitlements of the members cannot be dropped', function (): void {
    expect(groupObserverMembership($this->learner, $this->a))->toBe('active');
    groupObserverFakeMembershipEvents();
    $store = groupObserverStore('forgetGroup');
    Log::spy();

    $this->group->update(['organization_id' => $this->b->id]); // does not throw

    expect(groupObserverMembership($this->learner, $this->a))->toBe('left')
        ->and(groupObserverMembership($this->learner, $this->b))->toBe('active')
        // The relink first (the leave and the join each move the learner's own version), then the step that failed, then the overview of
        // both centers - the one the group left and the one it joined.
        ->and($store->calls)->toBe(['forget', 'forget', 'forgetGroup', 'overview', 'overview']);

    groupObserverExpectLogged('organizations.group_entitlement_forget_failed', $this->group, 'redis is down for forgetGroup');
    Log::shouldNotHaveReceived('warning', ['organizations.group_relink_failed', Mockery::any()]);
    Log::shouldNotHaveReceived('warning', ['organizations.group_overview_forget_failed', Mockery::any()]);
});

it('keeps the plan of a learner right when the group moves and the entitlements of its members cannot be dropped: the membership moves the version itself', function (): void {
    $c = Organization::factory()->create(['name' => 'Center C', 'slug' => 'center-c']); // no subscription: sponsors nobody
    groupObserverStore('forgetGroup'); // first: the snapshot below must be cached under the version this store holds
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($this->learner)->plan)->toBe(Plan::Pro); // seated in A, cached

    Log::spy();
    $this->group->update(['organization_id' => $c->id]);

    expect(groupObserverMembership($this->learner, $this->a))->toBe('left')
        ->and($resolver->for($this->learner)->plan)->toBe(Plan::Free, 'the leave raised the version, whatever happened to forgetGroup');
    groupObserverExpectLogged('organizations.group_entitlement_forget_failed', $this->group, 'redis is down for forgetGroup');
});

it('releases the members of a deleted group and still drops the overview when the entitlements of the members cannot be dropped', function (): void {
    groupObserverFakeMembershipEvents();
    $store = groupObserverStore('forgetMany');
    Log::spy();

    $this->group->delete(); // does not throw

    expect(TelegramGroup::query()->whereKey($this->group->id)->exists())->toBeFalse()
        ->and(groupObserverMembership($this->learner, $this->a))->toBe('left')
        // The release first (it moves the learner's own version), then the step that failed, then the overview of the center.
        ->and($store->calls)->toBe(['forget', 'forgetMany', 'overview']);

    groupObserverExpectLogged('organizations.group_entitlement_forget_failed', $this->group, 'redis is down for forgetMany');
    Log::shouldNotHaveReceived('warning', ['organizations.group_delete_release_failed', Mockery::any()]);
    Log::shouldNotHaveReceived('warning', ['organizations.group_overview_forget_failed', Mockery::any()]);
});

it('drops nothing while the delete is still to run: the entitlements of the members go after the release of the orphans', function (): void {
    groupObserverFakeMembershipEvents();
    $store = groupObserverStore();
    $callsBeforeTheDelete = null;
    $existsWhenDropped = null;

    // `deleting` fires before the row is removed. The observer only enumerates the members there (the cascade is about to take their
    // rows); it used to register the drop right in `deleting`, which ran at once when no transaction was open - before the delete.
    TelegramGroup::deleting(function () use ($store, &$callsBeforeTheDelete): void {
        $callsBeforeTheDelete = $store->calls;
    });
    TelegramGroup::deleted(function (TelegramGroup $group) use (&$existsWhenDropped): void {
        $existsWhenDropped = DB::table('telegram_groups')->where('id', $group->id)->exists();
    });

    $this->group->delete();

    expect($callsBeforeTheDelete)->toBe([], 'nothing was dropped while the delete was still to run')
        ->and($existsWhenDropped)->toBeFalse()
        ->and($store->calls)->toBe(['forget', 'forgetMany', 'overview']);
});

it('drops the entitlements of the members of a deleted group that belonged to no center, and does nothing for an empty one', function (): void {
    $free = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => null]);
    $one = Learner::factory()->memberOf($free)->create();
    $two = Learner::factory()->memberOf($free)->create();
    GroupMember::factory()->left()->create(['telegram_group_id' => $free->id, 'learner_id' => Learner::factory()->create()->id]);
    $empty = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => null]);
    $store = groupObserverStore();

    $free->delete();

    // The two active members, one call for each; nobody of the center, so no release and no overview.
    expect($store->calls)->toBe(['forgetMany', 'forgetMany'])
        ->and(EntitlementResolver::version($one->id))->toBe(1)
        ->and(EntitlementResolver::version($two->id))->toBe(1);

    $store->calls = [];
    $empty->delete();

    expect($store->calls)->toBe([]);
});

it('does not fail the delete of a group without a center when the entitlements of the members cannot be dropped', function (): void {
    $free = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => null]);
    Learner::factory()->memberOf($free)->create();
    groupObserverStore('forgetMany');
    Log::spy();

    $free->delete(); // does not throw

    expect(TelegramGroup::query()->whereKey($free->id)->exists())->toBeFalse();
    groupObserverExpectLogged('organizations.group_entitlement_forget_failed', $free, 'redis is down for forgetMany');
});

it('drops the entitlements of the members alone when only the status of the group changes, and logs a failure instead of throwing', function (): void {
    $store = groupObserverStore();

    $this->group->update(['status' => 'disabled']);

    expect($store->calls)->toBe(['forgetGroup'], 'no relink, no overview')
        ->and(groupObserverMembership($this->learner, $this->a))->toBe('active')
        ->and(EntitlementResolver::version($this->learner->id))->toBeGreaterThan(0);

    $store->down = ['forgetGroup'];
    Log::spy();

    $this->group->update(['status' => 'active']); // does not throw

    expect($this->group->fresh()?->status)->toBe('active');
    groupObserverExpectLogged('organizations.group_entitlement_forget_failed', $this->group, 'redis is down for forgetGroup');
});

it('drops the entitlements of the members once when the center and the status change together', function (): void {
    groupObserverFakeMembershipEvents();
    $store = groupObserverStore();

    $this->group->update(['organization_id' => $this->b->id, 'status' => 'disabled']);

    expect(array_count_values($store->calls)['forgetGroup'] ?? 0)->toBe(1)
        ->and(groupObserverMembership($this->learner, $this->b))->toBe('active');
});

it('leaves the cache alone for a change of the group that is none of its inputs', function (): void {
    $store = groupObserverStore();

    $this->group->update(['title' => 'Renamed']);

    expect($store->calls)->toBe([]);
});

it('does not run the steps of a group that was moved inside a transaction that is rolled back', function (): void {
    groupObserverFakeMembershipEvents();
    $store = groupObserverStore();

    try {
        DB::transaction(function (): void {
            $this->group->update(['organization_id' => $this->b->id]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($store->calls)->toBe([])
        ->and($this->group->fresh()?->organization_id)->toBe($this->a->id)
        ->and(groupObserverMembership($this->learner, $this->a))->toBe('active');
});
