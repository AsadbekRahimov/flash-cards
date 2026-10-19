<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PlatformStatsService;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\DailyStatsRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\ProductEventsRelationManager;
use App\Filament\Resources\LearnerResource\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\OnboardingFunnelWidget;
use App\Filament\Widgets\OrganizationSeatsTableWidget;
use App\Filament\Widgets\PlatformSubscriptionsWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function pa_user(string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function pa_event(string $name, Learner $learner, string $when = 'now', array $props = []): ProductEvent
{
    return ProductEvent::query()->create([
        'name' => $name,
        'learner_id' => $learner->id,
        'props' => $props,
        'occurred_at' => new DateTimeImmutable($when),
    ]);
}

function pa_pro(Learner|Organization $subject, string $until = '+30 days', array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'subscriber_type' => $subject instanceof Learner ? 'learner' : 'organization',
        'subscriber_id' => $subject->id,
        'current_period_ends_at' => $until === 'never' ? null : new DateTimeImmutable($until),
        ...$attributes,
    ]);
}

// --- widgets: access ---------------------------------------------------------

it('keeps the platform widgets away from teachers and inactive admins', function (): void {
    $widgets = [PlatformSubscriptionsWidget::class, OnboardingFunnelWidget::class, OrganizationSeatsTableWidget::class];

    $this->actingAs(pa_user('admin'));
    foreach ($widgets as $widget) {
        expect($widget::canView())->toBeTrue();
    }

    foreach ([pa_user('teacher'), pa_user('admin', active: false)] as $viewer) {
        $this->actingAs($viewer);
        foreach ($widgets as $widget) {
            expect($widget::canView())->toBeFalse();
        }
    }
});

it('answers 403 when a teacher mounts a platform widget directly through Livewire', function (): void {
    ProductEvent::query()->create(['name' => 'onboarding.started', 'props' => [], 'occurred_at' => now()]);
    $this->actingAs(pa_user('teacher'));

    foreach ([PlatformSubscriptionsWidget::class, OnboardingFunnelWidget::class, OrganizationSeatsTableWidget::class] as $widget) {
        Livewire::test($widget)->assertForbidden();
    }
});

it('re-checks the role on every Livewire update, not only on mount', function (): void {
    $admin = pa_user('admin');
    $this->actingAs($admin);
    $component = Livewire::test(OnboardingFunnelWidget::class)->assertOk();

    $admin->update(['is_active' => false]);

    $component->call('$refresh')->assertForbidden();
});

it('does not render the platform widgets on the dashboard of a teacher', function (): void {
    $teacher = pa_user('teacher');
    $teacher->telegramGroups()->attach(TelegramGroup::factory()->create()->id);

    $this->actingAs($teacher)->get('/admin')
        ->assertOk()
        ->assertDontSee('Onboarding funnel')
        ->assertDontSee('Pro subscriptions')
        ->assertDontSee('members / seats');
});

// --- widgets: numbers --------------------------------------------------------

it('counts platform-wide learners and 7-day activity for an admin', function (): void {
    Learner::factory()->create(['last_seen_at' => now()->subDay()]);
    Learner::factory()->create(['last_seen_at' => now()->subDays(30)]);
    Learner::factory()->create(['last_seen_at' => null]);

    $this->actingAs(pa_user('admin'));

    Livewire::test(TotalLearnersWidget::class)
        ->assertSee('Total learners')
        ->assertSee('Active last 7 days')
        ->assertSee('3')
        ->assertSee('1');
});

it('computes Pro subscription figures: own, sponsored, expiring; ended and suspended do not count', function (): void {
    $active = Learner::factory()->create();
    $expiring = Learner::factory()->create();
    $perpetual = Learner::factory()->create();
    $pastDue = Learner::factory()->create();
    $lapsed = Learner::factory()->create();
    $canceled = Learner::factory()->create();
    Learner::factory()->create(); // Free

    pa_pro($active, '+30 days');
    pa_pro($expiring, '+3 days');
    pa_pro($perpetual, 'never');
    pa_pro($pastDue, '-1 day', ['status' => 'past_due', 'provider' => 'payme', 'grace_until' => now()->addDays(2)]);
    pa_pro($lapsed, '-1 day'); // manual: ran out, the expire job has not run yet
    pa_pro($canceled, '+30 days', ['status' => 'canceled']);

    // Sponsored: org A active, 2 members (one of them also has a personal Pro), expires in 5 days.
    $orgA = Organization::factory()->create();
    $groupA = TelegramGroup::factory()->create(['organization_id' => $orgA->id, 'status' => 'active']);
    Learner::factory()->memberOf($groupA)->count(2)->create();
    $active->groups()->attach($groupA->id, ['status' => 'active', 'joined_at' => now()]);
    pa_pro($orgA, '+5 days');
    // Org B is suspended: its members stay Free and it is not counted as sponsoring.
    $orgB = Organization::factory()->suspended()->create();
    $groupB = TelegramGroup::factory()->create(['organization_id' => $orgB->id, 'status' => 'active']);
    Learner::factory()->memberOf($groupB)->create();
    pa_pro($orgB, '+2 days');
    // A member who left does not get sponsored Pro.
    Learner::factory()->memberOf($groupA, 'left')->create();

    $stats = app(PlatformStatsService::class)->subscriptions();

    expect($stats)->toBe([
        // active, expiring, perpetual, pastDue + 2 sponsored members (the third is `active`, counted once)
        'pro_learners' => 6,
        'personal' => 4,
        'organizations' => 1,
        'expiring' => 3, // $expiring (3 days), $pastDue (grace ends in 2 days) and org A (5 days)
    ]);

    $this->actingAs(pa_user('admin'));
    Livewire::test(PlatformSubscriptionsWidget::class)
        ->assertSee('Pro learners')
        ->assertSee('6')
        ->assertSee('4 own, 1 sponsoring organizations');
});

it('adds the grace period of an auto-renewing provider before a subscription stops granting', function (): void {
    $graceful = Learner::factory()->create();
    $strict = Learner::factory()->create();
    // payme: the period ended a day ago, inside the 3 grace days (status still `active`: the job is late).
    pa_pro($graceful, '-1 day', ['provider' => 'payme']);
    pa_pro($strict, '-1 day', ['provider' => 'promo']);

    expect(app(PlatformStatsService::class)->subscriptions()['personal'])->toBe(1);
});

it('builds the onboarding funnel as a 7-day cohort of distinct learners', function (): void {
    [$l1, $l2, $l3, $old, $orphan] = Learner::factory()->count(5)->create()->all();

    pa_event('onboarding.started', $l1, '-2 days');
    pa_event('onboarding.started', $l1, '-1 day'); // a retried event: still one learner
    pa_event('onboarding.completed', $l1, '-1 day');
    pa_event('onboarding.started', $l2, '-3 days');
    pa_event('onboarding.completed', $l2, '-2 days');
    pa_event('onboarding.started', $l3, '-1 day'); // never finished
    pa_event('onboarding.started', $old, '-10 days'); // started before the window ...
    pa_event('onboarding.completed', $old, '-1 day'); // ... so not part of this cohort
    pa_event('onboarding.completed', $orphan, '-1 day'); // no start event at all

    expect(app(PlatformStatsService::class)->onboardingFunnel())->toBe(['started' => 3, 'completed' => 2, 'conversion' => 66.7]);

    $this->actingAs(pa_user('admin'));
    Livewire::test(OnboardingFunnelWidget::class)
        ->assertSee('Started onboarding')
        ->assertSee('66.7%');
});

it('shows a dash instead of a conversion while nobody started onboarding', function (): void {
    expect(app(PlatformStatsService::class)->onboardingFunnel())->toBe(['started' => 0, 'completed' => 0, 'conversion' => null]);

    $this->actingAs(pa_user('admin'));
    Livewire::test(OnboardingFunnelWidget::class)->assertSee('—');
});

it('caches the aggregates briefly and recomputes after flush', function (): void {
    $service = app(PlatformStatsService::class);
    pa_event('onboarding.started', Learner::factory()->create(), '-1 day');
    expect($service->onboardingFunnel()['started'])->toBe(1);

    pa_event('onboarding.started', Learner::factory()->create(), '-1 day');
    DB::enableQueryLog();
    expect($service->onboardingFunnel()['started'])->toBe(1)
        ->and(DB::getQueryLog())->toBe([]);

    $service->flush();
    expect($service->onboardingFunnel()['started'])->toBe(2);
});

it('lists members and seats per organization from active memberships of active groups', function (): void {
    $center = Organization::factory()->create(['name' => 'Alpha Center']);
    $active = TelegramGroup::factory()->create(['organization_id' => $center->id, 'status' => 'active']);
    $second = TelegramGroup::factory()->create(['organization_id' => $center->id, 'status' => 'active']);
    $disabled = TelegramGroup::factory()->create(['organization_id' => $center->id, 'status' => 'disabled']);

    $both = Learner::factory()->memberOf($active)->create();
    $both->groups()->attach($second->id, ['status' => 'active', 'joined_at' => now()]);
    Learner::factory()->memberOf($second)->create();
    Learner::factory()->memberOf($active, 'left')->create();
    Learner::factory()->memberOf($disabled)->create();
    Learner::factory()->memberOf($active)->create(['deleted_at' => now()]);
    pa_pro($center, 'never', ['seats_limit' => 50]);

    $empty = Organization::factory()->create(['name' => 'Beta Center']);

    $this->actingAs(pa_user('admin'));

    Livewire::test(OrganizationSeatsTableWidget::class)
        ->assertCanSeeTableRecords([$center, $empty], inOrder: true)
        ->assertTableColumnStateSet('members_count', 2, $center)
        ->assertTableColumnStateSet('active_groups_count', 2, $center)
        ->assertTableColumnStateSet('seats_limit', 50, $center)
        ->assertTableColumnStateSet('members_count', 0, $empty)
        ->assertTableColumnStateSet('seats_limit', null, $empty);
});

// --- learner list ------------------------------------------------------------

it('searches learners by name, @username and exact Telegram ID for an admin', function (): void {
    $anna = Learner::factory()->create(['first_name' => 'Anna', 'last_name' => 'Karimova', 'username' => 'annak', 'telegram_user_id' => 123456789]);
    $boris = Learner::factory()->create(['first_name' => 'Boris', 'last_name' => 'Petrov', 'username' => 'bpetrov', 'telegram_user_id' => 987654321]);

    $this->actingAs(pa_user('admin'));

    Livewire::test(ListLearners::class)
        ->searchTable('Karim')->assertCanSeeTableRecords([$anna])->assertCanNotSeeTableRecords([$boris])
        ->searchTable('@bpetr')->assertCanSeeTableRecords([$boris])->assertCanNotSeeTableRecords([$anna])
        ->searchTable('bpetrov')->assertCanSeeTableRecords([$boris])
        ->searchTable('123456789')->assertCanSeeTableRecords([$anna])->assertCanNotSeeTableRecords([$boris])
        ->searchTable('12345')->assertCanNotSeeTableRecords([$anna, $boris]); // an identity: no partial match
});

it('does not let a teacher find a learner by Telegram ID', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = pa_user('teacher');
    $teacher->telegramGroups()->attach($group->id);
    $mine = Learner::factory()->memberOf($group)->create(['first_name' => 'Mine', 'telegram_user_id' => 555000111]);

    $this->actingAs($teacher);

    Livewire::test(ListLearners::class)
        ->searchTable('555000111')->assertCanNotSeeTableRecords([$mine])
        ->searchTable('Mine')->assertCanSeeTableRecords([$mine]);
});

it('filters learners by onboarding state', function (): void {
    $done = Learner::factory()->create(['onboarding_state' => 'done']);
    $midway = Learner::factory()->create(['onboarding_state' => 'goal']);
    $fresh = Learner::factory()->create(['onboarding_state' => 'new']);

    $this->actingAs(pa_user('admin'));

    Livewire::test(ListLearners::class)
        ->filterTable('onboarded', true)
        ->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$midway, $fresh])
        ->filterTable('onboarded', false)
        ->assertCanSeeTableRecords([$midway, $fresh])->assertCanNotSeeTableRecords([$done]);
});

it('filters learners Free / Pro by effective plan, personal and sponsored', function (): void {
    $free = Learner::factory()->create();
    $personal = Learner::factory()->create();
    $ended = Learner::factory()->create();
    $sponsored = Learner::factory()->create();
    $orphanedSponsor = Learner::factory()->create(); // member of a suspended organization
    $leftSponsor = Learner::factory()->create();     // left the sponsored group

    pa_pro($personal);
    pa_pro($ended, '-1 day');
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
    $sponsored->groups()->attach($group->id, ['status' => 'active', 'joined_at' => now()]);
    $leftSponsor->groups()->attach($group->id, ['status' => 'left', 'joined_at' => now(), 'left_at' => now()]);
    pa_pro($org);
    $suspended = Organization::factory()->suspended()->create();
    $suspendedGroup = TelegramGroup::factory()->create(['organization_id' => $suspended->id, 'status' => 'active']);
    $orphanedSponsor->groups()->attach($suspendedGroup->id, ['status' => 'active', 'joined_at' => now()]);
    pa_pro($suspended);

    $this->actingAs(pa_user('admin'));

    Livewire::test(ListLearners::class)
        ->filterTable('plan', 'pro')
        ->assertCanSeeTableRecords([$personal, $sponsored])
        ->assertCanNotSeeTableRecords([$free, $ended, $orphanedSponsor, $leftSponsor])
        ->filterTable('plan', 'free')
        ->assertCanSeeTableRecords([$free, $ended, $orphanedSponsor, $leftSponsor])
        ->assertCanNotSeeTableRecords([$personal, $sponsored])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$free, $personal, $sponsored]);
});

it('agrees with EntitlementResolver on who is Pro', function (): void {
    $learners = Learner::factory()->count(4)->create();
    pa_pro($learners[0]);
    pa_pro($learners[1], 'never');
    pa_pro($learners[2], '-2 days', ['provider' => 'telegram_stars']); // inside grace
    pa_pro($learners[3], '-5 days', ['provider' => 'telegram_stars']); // grace over

    $this->actingAs(pa_user('admin'));
    $viaFilter = Livewire::test(ListLearners::class)->filterTable('plan', 'pro');
    $resolver = app(EntitlementResolver::class);

    foreach ($learners as $learner) {
        $isPro = $resolver->resolve($learner)->plan->value === 'pro';
        $isPro ? $viaFilter->assertCanSeeTableRecords([$learner]) : $viaFilter->assertCanNotSeeTableRecords([$learner]);
    }
});

it('shows the plan column from one query, without a lookup per row', function (): void {
    $this->actingAs(pa_user('admin'));
    $count = function (int $learners): int {
        Learner::query()->forceDelete();
        Learner::factory()->count($learners)->create()->each(fn (Learner $l) => pa_pro($l));

        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(ListLearners::class)->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(1); // warm-up: roles, container singletons

    expect($count(12))->toBe($count(2));
});

it('labels the plan of a learner: Pro, Pro (sponsored) or Free', function (): void {
    $personal = Learner::factory()->create();
    $sponsored = Learner::factory()->create();
    $free = Learner::factory()->create();
    pa_pro($personal);
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
    $sponsored->groups()->attach($group->id, ['status' => 'active', 'joined_at' => now()]);
    pa_pro($org);

    $this->actingAs(pa_user('admin'));

    Livewire::test(ListLearners::class)
        ->assertTableColumnStateSet('plan', 'Pro', $personal)
        ->assertTableColumnStateSet('plan', 'Pro (sponsored)', $sponsored)
        ->assertTableColumnStateSet('plan', 'Free', $free);
});

it('ignores the platform filters and hides the platform columns for a teacher', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = pa_user('teacher');
    $teacher->telegramGroups()->attach($group->id);
    $pro = Learner::factory()->memberOf($group)->create();
    $free = Learner::factory()->memberOf($group)->create();
    pa_pro($pro);

    $this->actingAs($teacher);

    Livewire::test(ListLearners::class)
        ->assertTableColumnHidden('plan')
        ->assertTableColumnHidden('telegram_user_id')
        ->assertTableFilterHidden('plan')
        // A forged filter state reveals nothing extra: the plan filter is a no-op for a teacher.
        ->set('tableFilters.plan.value', 'pro')
        ->assertCanSeeTableRecords([$pro, $free]);

    expect(LearnerResource::getEloquentQuery()->first()->getAttributes())->not->toHaveKeys(['personal_pro_id', 'sponsor_pro_id']);
});

// --- ban -> TWA --------------------------------------------------------------

it('blocks a learner from the panel and the next TWA request gets 401 learner_blocked', function (): void {
    $admin = pa_user('admin');
    $learner = Learner::factory()->create();
    $token = app(JwtService::class)->issue($learner->id)['token'];

    $this->withToken($token)->getJson('/api/twa/me')->assertOk();

    $this->actingAs($admin);
    Livewire::test(ListLearners::class)->callTableAction('toggleBlocked', $learner);

    expect($learner->fresh()->blocked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.blocked')->count())->toBe(1);

    $this->withToken($token)->getJson('/api/twa/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'learner_blocked');

    Livewire::test(ListLearners::class)->callTableAction('toggleBlocked', $learner->fresh());
    $this->withToken($token)->getJson('/api/twa/me')->assertOk();
});

// --- learner page ------------------------------------------------------------

it('shows the subscriptions, the last 50 events and 14 days of stats on a learner page for an admin', function (): void {
    $learner = Learner::factory()->create();
    $other = Learner::factory()->create();
    $subscription = pa_pro($learner);
    $foreign = pa_pro($other);

    foreach (range(1, 55) as $i) {
        pa_event('training.session_finished', $learner, "-{$i} minutes");
    }
    $oldest = pa_event('onboarding.started', $learner, '-30 days');
    $foreignEvent = pa_event('onboarding.started', $other);

    $recent = LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => now()->subDays(13)->toDateString()]);
    $stale = LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => now()->subDays(14)->toDateString()]);
    $foreignStat = LearnerDailyStats::factory()->create(['learner_id' => $other->id, 'date' => now()->toDateString()]);

    $this->actingAs(pa_user('admin'));

    $props = ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class];

    Livewire::test(SubscriptionsRelationManager::class, $props)
        ->assertCanSeeTableRecords([$subscription])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertSee(SubscriptionResource::getUrl('index', ['filters' => ['learner' => ['learner_id' => $learner->id]]]), escape: false);

    $events = Livewire::test(ProductEventsRelationManager::class, $props)
        ->assertCanNotSeeTableRecords([$oldest, $foreignEvent]);
    expect($events->instance()->getTableRecords())->toHaveCount(ProductEventsRelationManager::LIMIT);

    Livewire::test(DailyStatsRelationManager::class, $props)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$stale, $foreignStat]);

    $this->withSession(['2fa.passed_at' => now()->timestamp])
        ->get(LearnerResource::getUrl('view', ['record' => $learner]))
        ->assertOk()
        ->assertSee('Subscriptions')
        ->assertSee('Recent events')
        ->assertSee('Last 14 days');
});

it('offers the learner page to a teacher without the platform tabs and without Telegram ID or plan', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = pa_user('teacher');
    $teacher->telegramGroups()->attach($group->id);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 777888999]);
    pa_pro($learner);

    $this->actingAs($teacher)->get(LearnerResource::getUrl('view', ['record' => $learner]))
        ->assertOk()
        ->assertDontSee('777888999')
        ->assertDontSee('Recent events')
        ->assertDontSee('Last 14 days')
        ->assertDontSee('Subscriptions');

    $props = ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class];
    foreach ([SubscriptionsRelationManager::class, ProductEventsRelationManager::class, DailyStatsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($learner, ViewLearner::class))->toBeFalse();
        Livewire::test($manager, $props)->assertForbidden();
    }
});

it('links from the learner page to the Subscriptions screen filtered by that learner', function (): void {
    $learner = Learner::factory()->create();
    $mine = pa_pro($learner);
    $theirs = pa_pro(Learner::factory()->create());

    $this->actingAs(pa_user('admin'));

    Livewire::withQueryParams(['filters' => ['learner' => ['learner_id' => (string) $learner->id]]])
        ->test(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

// --- review 1.8 ---------------------------------------------------------------

it('captions the plan the same way in the list column and on the learner page', function (): void {
    $personal = Learner::factory()->create();
    $sponsored = Learner::factory()->create();
    $free = Learner::factory()->create();
    pa_pro($personal, 'never');
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
    $sponsored->groups()->attach($group->id, ['status' => 'active', 'joined_at' => now()]);
    pa_pro($org, 'never');

    $this->actingAs(pa_user('admin'));

    expect(LearnerResource::planLabel(Plan::Free, false))->toBe('Free')
        ->and(LearnerResource::planLabel(Plan::Pro, false))->toBe('Pro')
        ->and(LearnerResource::planLabel(Plan::Pro, true))->toBe('Pro (sponsored)')
        ->and(LearnerResource::planLabel(Plan::Free, true))->toBe('Free');

    foreach ([[$personal, 'Pro'], [$sponsored, 'Pro (sponsored)'], [$free, 'Free']] as [$learner, $label]) {
        Livewire::test(ListLearners::class)->assertTableColumnStateSet('plan', $label, $learner);
        Livewire::test(ViewLearner::class, ['record' => $learner->getKey()])->assertSeeText($label);
    }
    Livewire::test(ViewLearner::class, ['record' => $sponsored->getKey()])->assertSeeText('Pro (sponsored), organization #'.$org->id);
    Livewire::test(ViewLearner::class, ['record' => $personal->getKey()])->assertDontSeeText('sponsored');
});

it('hides twa.authenticated in the recent events until it is picked in the filter, and filters by event name', function (): void {
    $learner = Learner::factory()->create();
    foreach (range(1, 60) as $i) {
        pa_event('twa.authenticated', $learner, "-{$i} seconds");
    }
    $started = pa_event('onboarding.started', $learner, '-2 hours');
    $finished = pa_event('training.session_finished', $learner, '-3 hours');
    $auth = ProductEvent::query()->where('name', 'twa.authenticated')->first();

    $this->actingAs(pa_user('admin'));
    $props = ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class];

    // By default the 60 logins do not push the real events out of the 50.
    Livewire::test(ProductEventsRelationManager::class, $props)
        ->assertCanSeeTableRecords([$started, $finished])
        ->assertCanNotSeeTableRecords([$auth]);

    Livewire::test(ProductEventsRelationManager::class, $props)
        ->filterTable('name', 'onboarding.started')
        ->assertCanSeeTableRecords([$started])
        ->assertCanNotSeeTableRecords([$finished, $auth]);

    $logins = Livewire::test(ProductEventsRelationManager::class, $props)->filterTable('name', 'twa.authenticated');
    expect($logins->instance()->getTableRecords())->toHaveCount(ProductEventsRelationManager::LIMIT);
    $logins->assertCanNotSeeTableRecords([$started]);
});
