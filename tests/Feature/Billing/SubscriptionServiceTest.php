<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Events\SubscriptionRenewalDue;
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
use Illuminate\Support\Facades\Event;

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
        ->whereIn('status', SubscriptionStatus::liveValues())
        ->count();
}

/** A live Stars order + subscription pair, as PaymentApplier would leave them after the first charge. */
function subscriptionStarsLive(Learner $learner, int $endsInDays = 20): Subscription
{
    return Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'provider_ref' => 'chg_first',
        'current_period_ends_at' => CarbonImmutable::now()->addDays($endsInDays),
        'meta' => ['stars' => ['charge_id' => 'chg_first']],
    ]);
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

    // A second live row (any live status, `canceled` included — docs/13 §2.1) violates the partial unique index on PG and sqlite.
    expect(fn () => DB::transaction(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'past_due'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => Subscription::factory()->create(['subscriber_id' => $learner->id, 'status' => 'canceled'])))
        ->toThrow(QueryException::class);

    // Non-live rows are not limited.
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);
    Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);
    expect(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(3);
})->skip(fn (): bool => ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true), 'Partial unique index exists on PG and sqlite only.');

it('extends a canceled subscription in place on a manual grant: one live row, back to active', function (): void {
    $learner = Learner::factory()->create();
    $canceled = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'status' => 'canceled',
        'canceled_at' => CarbonImmutable::now()->subDay(),
        'current_period_ends_at' => CarbonImmutable::now()->addDays(10),
    ]);
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro); // canceled is live until the period end

    $sub = app(SubscriptionService::class)->grantManual($learner, Plan::Pro, CarbonImmutable::now()->addDays(90), subscriptionAdmin(), 'goodwill');

    expect($sub->id)->toBe($canceled->id)
        ->and($sub->status)->toBe('active')
        ->and($sub->canceled_at)->toBeNull()
        ->and($sub->meta['history'][0]['from']['status'])->toBe('canceled')
        ->and(subscriptionLiveCount($learner))->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($resolver->for($learner)->until?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(90)->toIso8601String());
});

it('grants an organization a tier: seats_limit comes from config, an unknown tier is refused', function (): void {
    $org = Organization::factory()->create();
    $service = app(SubscriptionService::class);

    $sub = $service->grantManual($org, Plan::Pro, null, subscriptionAdmin(), 'contract', tier: 'center_s');
    expect($sub->tier)->toBe('center_s')->and($sub->seats_limit)->toBe(100)->and($sub->plan)->toBe('pro');

    $sub = $service->grantManual($org, Plan::Pro, null, subscriptionAdmin(), 'bigger', tier: 'center_l');
    expect($sub->tier)->toBe('center_l')->and($sub->seats_limit)->toBeNull();

    $sub = $service->grantManual($org, Plan::Pro, null, subscriptionAdmin(), 'no tier given');
    expect($sub->tier)->toBe('center_l'); // untouched when no tier is passed

    try {
        $service->grantManual($org, Plan::Pro, null, subscriptionAdmin(), 'x', tier: 'platinum');
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::TIER_UNKNOWN)->and($e->httpStatus())->toBe(422);
    }

    // A learner never gets a tier.
    $learner = Learner::factory()->create();
    expect($service->grantManual($learner, Plan::Pro, null, subscriptionAdmin(), 'x', tier: 'tutor')->tier)->toBeNull();
});

it('cancels an active auto-renewing subscription: canceled, still live until the period end, audit + event + cache', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $service = app(SubscriptionService::class);
    $resolver = app(EntitlementResolver::class);
    $sub = subscriptionStarsLive($learner);
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $canceled = $service->cancel($sub, $learner, '10.0.0.3');

    expect($canceled->id)->toBe($sub->id)
        ->and($canceled->status)->toBe('canceled')
        ->and($canceled->canceled_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($canceled->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(20)->toIso8601String())
        ->and($sub->status)->toBe('canceled')
        ->and($canceled->meta['history'][0]['action'])->toBe('canceled')
        ->and($canceled->meta['history'][0]['by_user_id'])->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0); // a learner is not an admin: no audit row

    $event = ProductEvent::query()->where('name', 'subscription.canceled')->sole();
    expect($event->learner_id)->toBe($learner->id)->and($event->props['by'])->toBe('learner');

    // Still Pro, until the period end and without grace.
    expect(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse();
    $e = $resolver->for($learner);
    expect($e->plan)->toBe(Plan::Pro)->and($e->until?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(20)->toIso8601String());

    // Cancel twice -> not_cancelable; an admin cancel is audited.
    try {
        $service->cancel($sub, $admin);
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::NOT_CANCELABLE)->and($e->httpStatus())->toBe(409);
    }

    $other = Learner::factory()->create();
    $otherSub = subscriptionStarsLive($other);
    $service->cancel($otherSub, $admin, '10.0.0.4');
    expect(AuditLog::query()->where('action', 'subscription.canceled')->sole()->entity_id)->toBe($otherSub->id);
});

it('refuses to cancel a subscription of a provider that does not renew', function (string $provider): void {
    $learner = Learner::factory()->create();
    $sub = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => $provider]);

    try {
        app(SubscriptionService::class)->cancel($sub, $learner);
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::NOT_CANCELABLE)->and($e->getMessage())->toContain('does not renew');
    }
    expect($sub->refresh()->status)->toBe('active');
})->with(['manual', 'promo', 'reward', 'payme', 'click']);

it('resumes a canceled subscription while the period runs and refuses once it is over', function (): void {
    $learner = Learner::factory()->create();
    $service = app(SubscriptionService::class);
    $sub = subscriptionStarsLive($learner);
    $service->cancel($sub, $learner);

    $resumed = $service->resume($sub, $learner);
    expect($resumed->status)->toBe('active')
        ->and($resumed->canceled_at)->toBeNull()
        ->and($resumed->meta['history'][1]['action'])->toBe('resumed')
        ->and(ProductEvent::query()->where('name', 'subscription.resumed')->count())->toBe(1)
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse();

    // Not canceled -> not_resumable.
    try {
        $service->resume($sub, $learner);
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionException::NOT_RESUMABLE)->and($e->httpStatus())->toBe(409);
    }

    // Canceled and the period ended (scheduler not yet run) -> not_resumable.
    $sub->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()->subDays(5), 'current_period_ends_at' => CarbonImmutable::now()->subMinute()]);
    try {
        $service->resume($sub, $learner);
        $this->fail('expected SubscriptionException');
    } catch (SubscriptionException $e) {
        expect($e->getMessage())->toContain('period has ended');
    }
});

it('ends a live subscription now for a refund: expired, ended_at, event with the reason, optional audit', function (): void {
    $learner = Learner::factory()->create();
    $admin = subscriptionAdmin();
    $service = app(SubscriptionService::class);
    $sub = subscriptionStarsLive($learner);

    $ended = $service->endNow($sub, 'refund', $admin);

    expect($ended->status)->toBe('expired')
        ->and($ended->ended_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($sub->status)->toBe('expired')
        ->and($ended->meta['history'][0]['action'])->toBe('ended')
        ->and($ended->meta['history'][0]['reason'])->toBe('refund')
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->sole()->props['reason'])->toBe('refund')
        ->and(AuditLog::query()->where('action', 'subscription.ended')->sole()->meta['reason'])->toBe('refund')
        ->and(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Free);

    expect(fn () => $service->endNow($sub, 'refund', null))->toThrow(SubscriptionException::class);

    // A canceled one can be ended too (it is live); without an admin there is no audit row.
    $other = Learner::factory()->create();
    $canceled = Subscription::factory()->create(['subscriber_id' => $other->id, 'provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => CarbonImmutable::now()->subDay()]);
    expect($service->endNow($canceled, 'chargeback')->status)->toBe('expired')
        ->and(AuditLog::query()->count())->toBe(1);
});

it('grants reward days: a new reward subscription, or an extension of the live one; a perpetual grant is left alone', function (): void {
    $learner = Learner::factory()->create();
    $service = app(SubscriptionService::class);
    $now = CarbonImmutable::now();

    $sub = DB::transaction(fn () => $service->grantReward($learner, 7, 'referral:42', $now));
    expect($sub->provider)->toBe('reward')
        ->and($sub->provider_ref)->toBe('referral:42')
        ->and($sub->plan)->toBe('pro')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(7)->toIso8601String())
        ->and(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Pro);

    // Extension from the current end; the row keeps its original provider (history records the reward).
    $sub->update(['provider' => 'payme', 'status' => 'canceled']);
    $again = DB::transaction(fn () => $service->grantReward($learner, 7, 'referral:43', $now));
    expect($again->id)->toBe($sub->id)
        ->and($again->status)->toBe('active')
        ->and($again->provider)->toBe('payme')
        ->and($again->current_period_ends_at?->toIso8601String())->toBe($now->addDays(14)->toIso8601String())
        ->and($again->meta['history'][0]['action'])->toBe('reward')
        ->and($again->meta['history'][0]['source'])->toBe('referral:43')
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->where('props->provider', 'reward')->count())->toBe(2);

    // Perpetual: nothing to extend, no exception, no event.
    $sub->update(['current_period_ends_at' => null]);
    $same = DB::transaction(fn () => $service->grantReward($learner, 7, 'referral:44', $now));
    expect($same->current_period_ends_at)->toBeNull()
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->where('props->provider', 'reward')->count())->toBe(2);
});

it('raises renewal reminders once per (subscription, period end, days) for non-renewing live subscriptions only', function (): void {
    Event::fake([SubscriptionRenewalDue::class]);
    config()->set('billing.checkout.renewal_reminder_days', [3, 1]);
    $service = app(SubscriptionService::class);
    $now = CarbonImmutable::now();

    $payme3 = Subscription::factory()->create(['provider' => 'payme', 'current_period_ends_at' => $now->addDays(2)->addHours(12)]);
    $click1 = Subscription::factory()->create(['provider' => 'click', 'current_period_ends_at' => $now->addHours(20)]);
    $canceledStars = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => $now->subDay(), 'current_period_ends_at' => $now->addDays(3)]);
    $org = Subscription::factory()->forOrganization()->create(['provider' => 'payme', 'current_period_ends_at' => $now->addDay()]);
    // Not reminded: an auto-renewing Stars subscription, grants, a far-away end, an ended period, a Free plan.
    Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => $now->addDay()]);
    Subscription::factory()->create(['provider' => 'manual', 'current_period_ends_at' => $now->addDay()]);
    Subscription::factory()->create(['provider' => 'promo', 'current_period_ends_at' => $now->addDay()]);
    Subscription::factory()->create(['provider' => 'reward', 'current_period_ends_at' => $now->addDay()]);
    Subscription::factory()->create(['provider' => 'payme', 'current_period_ends_at' => $now->addDays(10)]);
    Subscription::factory()->create(['provider' => 'payme', 'current_period_ends_at' => $now->subMinute()]);
    Subscription::factory()->create(['provider' => 'payme', 'plan' => 'free', 'current_period_ends_at' => $now->addDay()]);

    expect($service->remindExpiring($now))->toBe(4);

    Event::assertDispatchedTimes(SubscriptionRenewalDue::class, 4);
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $payme3->id && $e->daysLeft === 3 && $e->subscriberType === 'learner');
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $click1->id && $e->daysLeft === 1);
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $canceledStars->id && $e->daysLeft === 3 && $e->provider === 'telegram_stars');
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $org->id && $e->subscriberType === 'organization' && $e->daysLeft === 1);
    expect($payme3->refresh()->meta['renewal_reminders'])->toBe(['3@'.$now->addDays(2)->addHours(12)->toIso8601String()]);

    // Same day again: nothing new. Two days later: the 1-day reminders of the 3-day ones.
    expect($service->remindExpiring($now->addHour()))->toBe(0)
        ->and($service->remindExpiring($now->addDays(2)))->toBe(2);
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $payme3->id && $e->daysLeft === 1);

    // A renewal moves the period end: the markers are keyed by it, so the reminders re-arm.
    $payme3->update(['current_period_ends_at' => $now->addDays(32)]);
    expect($service->remindExpiring($now->addDays(30)))->toBe(1);
    Event::assertDispatched(SubscriptionRenewalDue::class, fn (SubscriptionRenewalDue $e): bool => $e->subscriptionId === $payme3->id && $e->daysLeft === 3 && $e->endsAt->equalTo($now->addDays(32)));
});

it('expires due subscriptions: grants and pay-per-period at once, Stars via grace, canceled at period end', function (): void {
    $service = app(SubscriptionService::class);
    $resolver = app(EntitlementResolver::class);
    $now = CarbonImmutable::now();

    $manual = Subscription::factory()->create(['provider' => 'manual', 'current_period_ends_at' => $now->subMinute()]);
    $promo = Subscription::factory()->create(['provider' => 'promo', 'current_period_ends_at' => $now->subDays(2)]);
    $stars = Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => $now->subHour()]);
    $starsLate = Subscription::factory()->create(['provider' => 'telegram_stars', 'current_period_ends_at' => $now->subDays(10)]);
    $canceled = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => $now->subDays(5), 'current_period_ends_at' => $now->subMinute()]);
    $payme = Subscription::factory()->create(['provider' => 'payme', 'current_period_ends_at' => $now->subMinute()]); // no grace: pay-per-period (docs/13 §0)
    $graceOver = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'past_due', 'current_period_ends_at' => $now->subDays(5), 'grace_until' => $now->subMinute()]);
    $graceNull = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'past_due', 'current_period_ends_at' => $now->subDays(5), 'grace_until' => null]);
    $inGrace = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'past_due', 'current_period_ends_at' => $now->subDay(), 'grace_until' => $now->addDay()]);
    $canceledRunning = Subscription::factory()->create(['provider' => 'telegram_stars', 'status' => 'canceled', 'canceled_at' => $now->subDay(), 'current_period_ends_at' => $now->addDay()]);
    $future = Subscription::factory()->create(['current_period_ends_at' => $now->addDay()]);
    $perpetual = Subscription::factory()->create(['current_period_ends_at' => null]);

    foreach ([$manual, $promo, $stars] as $s) {
        $resolver->for(Learner::query()->findOrFail($s->subscriber_id));
    }

    expect($service->expireDue($now))->toBe(8);

    expect($manual->refresh()->status)->toBe('expired')
        ->and($manual->ended_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($promo->refresh()->status)->toBe('expired')
        ->and($stars->refresh()->status)->toBe('past_due')
        ->and($stars->grace_until?->toIso8601String())->toBe($now->subHour()->addDays(3)->toIso8601String())
        ->and($starsLate->refresh()->status)->toBe('expired')
        ->and($canceled->refresh()->status)->toBe('expired')
        ->and($payme->refresh()->status)->toBe('expired')
        ->and($graceOver->refresh()->status)->toBe('expired')
        ->and($graceNull->refresh()->status)->toBe('expired')
        ->and($inGrace->refresh()->status)->toBe('past_due')
        ->and($canceledRunning->refresh()->status)->toBe('canceled')
        ->and($future->refresh()->status)->toBe('active')
        ->and($perpetual->refresh()->status)->toBe('active');

    foreach ([$manual, $promo, $stars] as $s) {
        expect(Cache::has(EntitlementResolver::cacheKey($s->subscriber_id)))->toBeFalse();
    }

    expect(ProductEvent::query()->where('name', 'subscription.expired')->count())->toBe(7)
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->where('props->reason', 'grace_over')->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0); // the scheduler is not a human: no audit record

    // Idempotent: a second run has nothing to do.
    expect($service->expireDue($now))->toBe(0);

    // Three days later: the Stars grace runs out, the `inGrace` grace (+1 day) is over, the
    // `future` manual period (+1 day) has ended and the running canceled one (+1 day) is over
    // without grace; the perpetual one never expires.
    expect($service->expireDue($now->addDays(3)->subHour()->addSecond()))->toBe(4)
        ->and($stars->refresh()->status)->toBe('expired')
        ->and($inGrace->refresh()->status)->toBe('expired')
        ->and($future->refresh()->status)->toBe('expired')
        ->and($canceledRunning->refresh()->status)->toBe('expired')
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
