<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\SubscriptionService;
use App\Models\AuditLog;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'UTC'));
    config()->set('billing.grace_days', 3);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function subscriptionAdmin(): User
{
    return User::factory()->create();
}

function subscriptionLiveCount(Learner|Organization $subscriber): int
{
    return Subscription::query()
        ->where('subscriber_type', $subscriber instanceof Learner ? 'learner' : 'organization')
        ->where('subscriber_id', $subscriber->id)
        ->whereIn('status', ['trialing', 'active', 'past_due'])
        ->count();
}

it('grants a manual Pro subscription with audit record, product event and cache invalidation', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $resolver = app(EntitlementResolver::class);
    $until = CarbonImmutable::now()->addDays(30);

    expect($resolver->for($learner)->plan)->toBe(Plan::Free);

    $sub = app(SubscriptionService::class)->grantManual($learner, Plan::Pro, $until, $admin, 'beta tester', '10.0.0.1');

    expect($sub->subscriber_type)->toBe('learner')
        ->and($sub->subscriber_id)->toBe($learner->id)
        ->and($sub->plan)->toBe('pro')
        ->and($sub->status)->toBe('active')
        ->and($sub->provider)->toBe('manual')
        ->and($sub->starts_at->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($until->toIso8601String())
        ->and($sub->granted_by_user_id)->toBe($admin->id)
        ->and($sub->note)->toBe('beta tester');

    $audit = AuditLog::query()->where('action', 'subscription.granted')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->entity_type)->toBe(Subscription::class)
        ->and($audit->entity_id)->toBe($sub->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta['subscriber_id'])->toBe($learner->id)
        ->and($audit->meta['note'])->toBe('beta tester')
        ->and($audit->meta['extended'])->toBeFalse();

    $event = ProductEvent::query()->where('name', ProductEventName::SubscriptionGranted->value)->sole();
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->props['provider'])->toBe('manual');

    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse();
    $e = $resolver->for($learner);
    expect($e->plan)->toBe(Plan::Pro)->and($e->subscriptionId)->toBe($sub->id);
});

it('grants a perpetual subscription when until is null', function (): void {
    $learner = Learner::factory()->create();

    $sub = app(SubscriptionService::class)->grantManual($learner, Plan::Pro, null, subscriptionAdmin(), 'lifetime');

    expect($sub->current_period_ends_at)->toBeNull()
        ->and(app(EntitlementResolver::class)->for($learner)->until)->toBeNull();
});

it('extends an existing live subscription in place instead of creating a second live row', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $service = app(SubscriptionService::class);

    $promo = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'promo',
        'status' => 'past_due',
        'current_period_ends_at' => CarbonImmutable::now()->subDay(),
        'grace_until' => CarbonImmutable::now()->addDay(),
    ]);

    $sub = $service->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(90), $admin, 'extend');

    expect($sub->id)->toBe($promo->id)
        ->and($sub->status)->toBe('active')
        ->and($sub->provider)->toBe('manual')
        ->and($sub->grace_until)->toBeNull()
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(90)->toIso8601String())
        ->and($sub->meta['history'][0]['action'])->toBe('manual_grant')
        ->and($sub->meta['history'][0]['from']['provider'])->toBe('promo')
        ->and(subscriptionLiveCount($learner))->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);

    expect(AuditLog::query()->where('action', 'subscription.granted')->sole()->meta['extended'])->toBeTrue();
});

it('rejects granting Free and an end that is not in the future', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $service = app(SubscriptionService::class);

    expect(fn () => $service->grantManual($learner, Plan::Free, null, $admin, 'x'))
        ->toThrow(SubscriptionException::class, 'cannot be granted');
    expect(fn () => $service->grantManual($learner, Plan::Pro, CarbonImmutable::now(), $admin, 'x'))
        ->toThrow(SubscriptionException::class, 'must be in the future');

    try {
        $service->grantManual($learner, Plan::Pro, CarbonImmutable::now()->subMinute(), $admin, 'x');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::UNTIL_IN_PAST)->and($e->httpStatus())->toBe(422);
    }

    expect(Subscription::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('rejects a subscriber that does not exist', function (): void {
    $learner = Learner::factory()->make(['id' => 999_999]);

    expect(fn () => app(SubscriptionService::class)->grantManual($learner, Plan::Pro, null, subscriptionAdmin(), 'x'))
        ->toThrow(SubscriptionException::class, 'does not exist');
});

it('grants to an organization and invalidates all of its groups\' members', function (): void {
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['status' => 'active', 'organization_id' => $org->id]);
    $member = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $member->id]);
    $outsider = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($member)->plan)->toBe(Plan::Free)
        ->and($resolver->for($outsider)->plan)->toBe(Plan::Free);

    $sub = app(SubscriptionService::class)->grantManual($org, Plan::Pro, null, subscriptionAdmin(), 'center contract');

    expect($sub->subscriber_type)->toBe('organization')
        ->and(Cache::has(EntitlementResolver::cacheKey($member->id)))->toBeFalse()
        ->and(Cache::has(EntitlementResolver::cacheKey($outsider->id)))->toBeTrue()
        ->and($resolver->for($member)->source)->toBe("sponsor:organization:{$org->id}")
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->sole()->learner_id)->toBeNull();
});

it('revokes a live subscription with audit and invalidation, and refuses to revoke twice', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $service = app(SubscriptionService::class);
    $resolver = app(EntitlementResolver::class);

    $sub = $service->grantManual($learner, Plan::Pro, null, $admin, 'grant');
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $revoked = $service->revoke($sub, $admin, 'refund', '10.0.0.2');

    expect($revoked->id)->toBe($sub->id)
        ->and($revoked->status)->toBe('expired')
        ->and($revoked->ended_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($sub->status)->toBe('expired') // the passed-in model is refreshed
        ->and($revoked->meta['history'][0]['action'])->toBe('revoked')
        ->and(subscriptionLiveCount($learner))->toBe(0);

    $audit = AuditLog::query()->where('action', 'subscription.revoked')->sole();
    expect($audit->entity_id)->toBe($sub->id)->and($audit->meta['note'])->toBe('refund')->and($audit->ip)->toBe('10.0.0.2');
    expect(ProductEvent::query()->where('name', 'subscription.expired')->sole()->props['reason'])->toBe('revoked');

    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);

    try {
        $service->revoke($sub, $admin, 'again');
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::NOT_LIVE)->and($e->httpStatus())->toBe(409);
    }
    expect(AuditLog::query()->where('action', 'subscription.revoked')->count())->toBe(1);
});

it('keeps at most one live subscription per subscriber at the database level', function (): void {
    $learner = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'active']);

    // A second live row (any live status) violates the partial unique index on PG and sqlite.
    expect(fn () => DB::transaction(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'past_due'])))
        ->toThrow(QueryException::class);

    // Non-live rows are not limited.
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'canceled']);
    expect(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(3);
})->skip(fn (): bool => ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true), 'Partial unique index exists on PG and sqlite only.');

it('expires due subscriptions: manual and promo at once, auto-renewing via grace, canceled at period end', function (): void {
    $service = app(SubscriptionService::class);
    $resolver = app(EntitlementResolver::class);
    $now = CarbonImmutable::now();

    $manual = Subscription::factory()->create(['provider' => 'manual', 'current_period_ends_at' => $now->subMinute()]);
    $promo = Subscription::factory()->create(['provider' => 'promo', 'current_period_ends_at' => $now->subDays(2)]);
    $stars = Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => $now->subHour()]);
    $starsLate = Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => $now->subDays(10)]);
    $canceled = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => $now->subDays(5), 'current_period_ends_at' => $now->subMinute()]);
    $graceOver = Subscription::factory()->create(['provider' => 'payme', 'status' => 'past_due', 'current_period_ends_at' => $now->subDays(5), 'grace_until' => $now->subMinute()]);
    $graceNull = Subscription::factory()->create(['provider' => 'payme', 'status' => 'past_due', 'current_period_ends_at' => $now->subDays(5), 'grace_until' => null]);
    $inGrace = Subscription::factory()->create(['provider' => 'payme', 'status' => 'past_due', 'current_period_ends_at' => $now->subDay(), 'grace_until' => $now->addDay()]);
    $future = Subscription::factory()->create(['current_period_ends_at' => $now->addDay()]);
    $perpetual = Subscription::factory()->create(['current_period_ends_at' => null]);

    foreach ([$manual, $promo, $stars] as $s) {
        $resolver->for(Learner::query()->findOrFail($s->subscriber_id));
    }

    expect($service->expireDue($now))->toBe(7);

    expect($manual->refresh()->status)->toBe('expired')
        ->and($manual->ended_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($promo->refresh()->status)->toBe('expired')
        ->and($stars->refresh()->status)->toBe('past_due')
        ->and($stars->grace_until?->toIso8601String())->toBe($now->subHour()->addDays(3)->toIso8601String())
        ->and($starsLate->refresh()->status)->toBe('expired')
        ->and($canceled->refresh()->status)->toBe('expired')
        ->and($graceOver->refresh()->status)->toBe('expired')
        ->and($graceNull->refresh()->status)->toBe('expired')
        ->and($inGrace->refresh()->status)->toBe('past_due')
        ->and($future->refresh()->status)->toBe('active')
        ->and($perpetual->refresh()->status)->toBe('active');

    foreach ([$manual, $promo, $stars] as $s) {
        expect(Cache::has(EntitlementResolver::cacheKey($s->subscriber_id)))->toBeFalse();
    }

    expect(ProductEvent::query()->where('name', 'subscription.expired')->count())->toBe(6)
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->where('props->reason', 'grace_over')->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0); // the scheduler is not a human: no audit record

    // Idempotent: a second run has nothing to do.
    expect($service->expireDue($now))->toBe(0);

    // Three days later: the Stars grace runs out, the `inGrace` grace (+1 day) is over
    // and the `future` manual period (+1 day) has ended; the perpetual one never expires.
    expect($service->expireDue($now->addDays(3)->subHour()->addSecond()))->toBe(3)
        ->and($stars->refresh()->status)->toBe('expired')
        ->and($inGrace->refresh()->status)->toBe('expired')
        ->and($future->refresh()->status)->toBe('expired')
        ->and($perpetual->refresh()->status)->toBe('active');
});

it('does not expire a subscription that was extended after the candidate list was built', function (): void {
    $learner = Learner::factory()->create();
    $service = app(SubscriptionService::class);
    $sub = Subscription::factory()->create(['subscriber_id' => $learner->id, 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);

    // The scheduler's clock is behind the extension (a grant landed in between).
    $sub->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(10)]);

    expect($service->expireDue(CarbonImmutable::now()))->toBe(0)
        ->and($sub->refresh()->status)->toBe('active');
});

it('runs the subscriptions:expire command and has it scheduled hourly', function (): void {
    Subscription::factory()->create(['provider' => 'manual', 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);

    Artisan::call('subscriptions:expire');

    expect(Artisan::output())->toContain('Subscriptions transitioned: 1')
        ->and(Subscription::query()->where('status', 'expired')->count())->toBe(1);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'subscriptions:expire'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('exposes the live subscription of a subscriber for display', function (): void {
    $learner = Learner::factory()->create();
    $service = app(SubscriptionService::class);

    expect($service->liveFor($learner))->toBeNull();

    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);
    $live = Subscription::factory()->create(['subscriber_id' => $learner->id]);

    expect($service->liveFor($learner)?->id)->toBe($live->id);
});
