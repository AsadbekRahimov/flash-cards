<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeBillingProvider;

/*
 * Change of an organization's tariff (docs/13 §6.5, "Реализация 2B.8"): while the subscription is live an
 * online order may only RENEW the live tier — buying another tier would re-label the whole prepaid period
 * (12 months of Tutor + 1 month of Center M = 13 months of Center M; a prepaid Center M + 1 month of Tutor
 * = 270 seats lost for the rest of the term). A change goes through the platform (`grantManual`). The
 * guard is in CheckoutService (409 `tier_change_requires_support`) AND in SubscriptionService::applyCharge:
 * an order whose tier no longer matches by the time the money arrives is credited (the period is extended)
 * but the tier / seats stay, and the order is flagged for the platform (`meta.tier_mismatch`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    config()->set('billing.org_tiers.tutor.seats', 30);
    config()->set('billing.org_tiers.center_m.seats', 300);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function tcOrder(Organization $org, string $tier): PaymentOrder
{
    return app(CheckoutService::class)->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, $tier, CheckoutService::VIA_ORG_PANEL);
}

function tcRefused(Organization $org, string $tier): CheckoutException
{
    try {
        tcOrder($org, $tier);
    } catch (CheckoutException $e) {
        return $e;
    }

    throw new RuntimeException('Expected CheckoutException');
}

function tcPay(PaymentOrder $order, string $eventId): void
{
    app(PaymentApplier::class)->applyCharge(test()->payme->emitCharge($order, eventId: $eventId));
}

function tcGrant(Organization $org, string $tier, int $days): Subscription
{
    return app(SubscriptionService::class)->grantManual($org, Plan::Pro, CarbonImmutable::now()->addDays($days), test()->admin, 'by agreement', tier: $tier);
}

it('refuses an online order of another tier while the subscription is live: 12 months of Tutor cannot become 13 months of Center M', function (): void {
    tcGrant($this->org, 'tutor', 365);

    $e = tcRefused($this->org, 'center_m');

    expect($e->errorCode)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT)
        ->and($e->httpStatus())->toBe(409)
        ->and(PaymentOrder::query()->count())->toBe(0)
        ->and(Subscription::query()->sole()->tier)->toBe('tutor');
});

it('refuses a cheaper tier as well: a prepaid Center M does not lose its seats to one month of Tutor', function (): void {
    tcGrant($this->org, 'center_m', 365);

    $e = tcRefused($this->org, 'tutor');

    $sub = Subscription::query()->sole();
    expect($e->errorCode)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT)
        ->and($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300);
});

it('sells a renewal of the live tier: the period is extended from its end, tier and seats unchanged, nothing flagged', function (): void {
    Log::spy();
    tcGrant($this->org, 'tutor', 10);

    $order = tcOrder($this->org, 'tutor');
    tcPay($order, 'renew-1');

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('tutor')
        ->and($sub->seats_limit)->toBe(30)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(40)->toIso8601String())
        ->and($order->refresh()->status)->toBe(PaymentOrder::STATUS_PAID)
        ->and($order->meta)->not->toHaveKey('tier_mismatch')
        ->and(AuditLog::query()->where('action', SubscriptionService::ACTION_TIER_MISMATCH)->exists())->toBeFalse();
    Log::shouldNotHaveReceived('warning');
});

it('lets a center without a live subscription, or whose period has run out, buy any tier', function (): void {
    // No subscription at all.
    $first = tcOrder($this->org, 'tutor');
    tcPay($first, 'new-1');
    expect(Subscription::query()->sole()->tier)->toBe('tutor');

    // The period ran out but the hourly sweep has not expired the row yet: there is nothing prepaid to protect.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(31));
    $second = tcOrder($this->org, 'center_m');
    tcPay($second, 'new-2');

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and($second->refresh()->meta)->not->toHaveKey('tier_mismatch');

    // An expired row is not live either.
    $sub->forceFill(['status' => 'expired', 'ended_at' => CarbonImmutable::now()])->save();
    expect(tcOrder($this->org, 'tutor')->tier)->toBe('tutor');
});

it('lets a live subscription without a tier (an old grant) take the tier of the first paid order', function (): void {
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, CarbonImmutable::now()->addDays(100), $this->admin, 'old grant');

    tcPay(tcOrder($this->org, 'center_m'), 'untiered-1');

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_m')->and($sub->seats_limit)->toBe(300);
});

it('race: an order created before the platform changed the tier is credited, but the live tier wins and the order is flagged', function (): void {
    Log::spy();

    // The owner opened a Tutor order while the center had no subscription ...
    $order = tcOrder($this->org, 'tutor');

    // ... then the platform activated Center M by agreement (an invoice was paid) ...
    tcGrant($this->org, 'center_m', 365);

    // ... and the old Tutor order is paid afterwards: the money is taken, so the period is extended by its
    // month, but Center M and its 300 seats stay — a month of Tutor must not shrink a prepaid year of Center M.
    tcPay($order, 'race-1');

    $sub = Subscription::query()->sole();
    $order->refresh();
    expect($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300)
        ->and($sub->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(395)->toIso8601String())
        ->and($order->status)->toBe(PaymentOrder::STATUS_PAID)
        ->and($order->subscription_id)->toBe($sub->id)
        ->and($order->meta['tier_mismatch'])->toBeTrue()
        ->and($order->meta['tier_mismatch_live_tier'])->toBe('center_m')
        ->and(collect($sub->meta['history'])->last()['tier_mismatch'])->toEqualCanonicalizing(['ordered' => 'tutor', 'kept' => 'center_m']);

    $audit = AuditLog::query()->where('action', SubscriptionService::ACTION_TIER_MISMATCH)->sole();
    expect($audit->entity_id)->toBe($sub->id)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->meta['payment_order_id'])->toBe($order->id)
        ->and($audit->meta['ordered_tier'])->toBe('tutor')
        ->and($audit->meta['live_tier'])->toBe('center_m');

    Log::shouldHaveReceived('warning')->with('billing.org_tier_mismatch', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $order->id && $ctx['ordered_tier'] === 'tutor' && $ctx['live_tier'] === 'center_m'))->once();
});

it('race the other way: a Tutor renewal order outlives a manual move to Center M — paid, extended, Center M kept', function (): void {
    tcGrant($this->org, 'tutor', 20);
    $renewal = tcOrder($this->org, 'tutor');

    tcGrant($this->org, 'center_m', 200);
    tcPay($renewal, 'race-2');

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(230)->toIso8601String())
        ->and($renewal->refresh()->meta['tier_mismatch'])->toBeTrue();

    // A fresh order now carries the live tier and is a plain renewal.
    $next = tcOrder($this->org, 'center_m');
    tcPay($next, 'race-3');
    expect($next->refresh()->meta)->not->toHaveKey('tier_mismatch')
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(260)->toIso8601String());
});

it('a perpetual grant can be renewed with its own tier (money recorded, nothing to extend) but not re-tiered online', function (): void {
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, null, $this->admin, 'perpetual', tier: 'tutor');

    expect(tcRefused($this->org, 'center_m')->errorCode)->toBe(CheckoutException::TIER_CHANGE_REQUIRES_SUPPORT);

    $order = tcOrder($this->org, 'tutor');
    tcPay($order, 'perp-1');
    $sub = Subscription::query()->sole();
    expect($sub->current_period_ends_at)->toBeNull()->and($sub->tier)->toBe('tutor')->and($order->refresh()->status)->toBe(PaymentOrder::STATUS_PAID);
});
