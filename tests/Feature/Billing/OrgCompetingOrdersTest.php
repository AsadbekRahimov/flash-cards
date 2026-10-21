<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Providers\Click\ClickException;
use App\Domain\Billing\Providers\Click\ClickMerchantService;
use App\Domain\Billing\Providers\Payme\PaymeRpcException;
use App\Domain\Billing\Providers\Payme\PaymeRpcService;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\OrganizationOrderGuard;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ClickFixtures;
use Tests\Support\FakeBillingProvider;
use Tests\Support\PaymeFixtures;

/*
 * Competing organization orders (docs/13 "Реализация 2B.8", OrganizationOrderGuard). Up to three pending
 * orders live side by side for 24 h, so a center without a live subscription can open two Tutor orders and
 * one Center M, pay Center M and then pay both Tutor orders into a `tier_mismatch` — three months of
 * Center M for 849 000 + 2 x 129 000. Two guards close that: the provider pre-checks (Payme
 * CheckPerformTransaction / CreateTransaction, Click Prepare) refuse an organization order whose tier is
 * not the live one, or whose organization is suspended, BEFORE the money is taken; and a payment that sets
 * / keeps a tier cancels the organization's other pending orders of another tier (`tier_changed`) — except
 * one the provider is mid-payment on, which keeps the `tier_mismatch` backstop.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    config()->set('billing.org_tiers.tutor.seats', 30);
    config()->set('billing.org_tiers.center_m.seats', 300);
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    ClickFixtures::configure();
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->click = FakeBillingProvider::register(SubscriptionProvider::Click);
    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->create();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function coOrder(Organization $org, string $tier, SubscriptionProvider $provider = SubscriptionProvider::Payme): PaymentOrder
{
    return app(CheckoutService::class)->create($org, Plan::Pro, 'month', $provider, $tier, CheckoutService::VIA_ORG_PANEL);
}

/** Pays an order the way the provider's webhook would (Payme Perform / Click Complete deliver this event). */
function coPay(PaymentOrder $order, string $eventId): Payment
{
    $fake = $order->provider === 'click' ? test()->click : test()->payme;

    return app(PaymentApplier::class)->applyCharge($fake->emitCharge($order, eventId: $eventId));
}

/** @return array<string, mixed> */
function coPayme(string $method, PaymentOrder $order, string $transactionId = PaymeFixtures::TRANSACTION_ID): array
{
    return app(PaymeRpcService::class)->handle(PaymeFixtures::for($method, $order, [], $transactionId));
}

/** @return array<string, mixed> */
function coClickPrepare(PaymentOrder $order, string $clickTransId = ClickFixtures::CLICK_TRANS_ID): array
{
    return app(ClickMerchantService::class)->prepare(ClickFixtures::prepare($order, clickTransId: $clickTransId));
}

function coExpectPaymeError(array $response, int $code): void
{
    expect($response['error']['code'] ?? null)->toBe($code, 'Expected Payme error '.$code.', got '.json_encode($response))
        ->and($response['error']['data'] ?? null)->toBe('order_id');
}

// --- the reviewer's scenario --------------------------------------------------------------------

it('cancels the other pending orders of another tier once Center M is paid; their tokens die at every Payme / Click pre-check and no money is taken', function (): void {
    Log::spy();
    $tutorPayme = coOrder($this->org, 'tutor');
    $tutorClick = coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    $centerM = coOrder($this->org, 'center_m');

    coPay($centerM, 'center-m-1');

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and($centerM->refresh()->status)->toBe(PaymentOrder::STATUS_PAID);

    foreach ([$tutorPayme, $tutorClick] as $tutor) {
        $tutor->refresh();
        expect($tutor->status)->toBe(PaymentOrder::STATUS_CANCELED)
            ->and($tutor->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED)
            ->and($tutor->meta['tier_changed_by_order_id'])->toBe($centerM->id)
            ->and($tutor->meta['tier_changed_to'])->toBe('center_m')
            ->and($tutor->meta['canceled_at'])->toBe(CarbonImmutable::now()->toIso8601String())
            ->and($tutor->canceledBySystem())->toBeTrue()
            ->and($tutor->canceledForOffer())->toBeFalse();
    }
    Log::shouldHaveReceived('info')->with('billing.org_order_canceled', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $tutorPayme->id && $ctx['live_tier'] === 'center_m'))->once();

    // Payme: CheckPerformTransaction and CreateTransaction on the canceled Tutor order are refused (-31051: the order is canceled).
    coExpectPaymeError(coPayme('CheckPerformTransaction', $tutorPayme), PaymeRpcException::ORDER_NOT_PAYABLE);
    coExpectPaymeError(coPayme('CreateTransaction', $tutorPayme), PaymeRpcException::ORDER_NOT_PAYABLE);

    // Click: Prepare on the canceled Tutor order is -9 (closed on our side).
    expect(coClickPrepare($tutorClick)['error'])->toBe(ClickException::TRANSACTION_CANCELED);

    expect(ProviderTransaction::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String());
});

it('keeps the other pending orders of the SAME tier (a renewal still sells) and cancels only the other tiers', function (): void {
    $tutorA = coOrder($this->org, 'tutor');
    $tutorB = coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    $centerM = coOrder($this->org, 'center_m');

    coPay($tutorA, 'tutor-1');

    expect($tutorB->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($centerM->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($centerM->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED);

    // The same-tier order is a plain renewal: extended from the end, nothing flagged.
    coPay($tutorB, 'tutor-2');
    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('tutor')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(60)->toIso8601String())
        ->and($tutorB->refresh()->meta)->not->toHaveKey('tier_mismatch');
});

it('also sweeps after a renewal of the live tier and after a tier_mismatch payment (the live tier decides, not the order)', function (): void {
    // A Tutor order opened before the platform activated Center M by agreement ...
    $tutorOld = coOrder($this->org, 'tutor');
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, CarbonImmutable::now()->addDays(365), $this->admin, 'invoice paid', tier: 'center_m');
    // ... while another one is being paid at the provider right now (open Payme transaction).
    $tutorOpen = PaymentOrder::factory()->forOrganization($this->org, 'tutor')->create(['amount' => 12_900_000, 'list_amount' => 12_900_000]);
    ProviderTransaction::factory()->create(['provider' => 'payme', 'payment_order_id' => $tutorOpen->id, 'state' => ProviderTransaction::STATE_CREATED, 'created_at_provider' => CarbonImmutable::now()->subMinute()]);

    // A late Perform on the open one: credited, tier kept, flagged — and it sweeps the other Tutor order.
    coPay($tutorOpen, 'late-1');

    expect($tutorOpen->refresh()->meta['tier_mismatch'])->toBeTrue()
        ->and($tutorOld->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($tutorOld->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED)
        ->and($tutorOld->meta['tier_changed_to'])->toBe('center_m')
        ->and(Subscription::query()->sole()->tier)->toBe('center_m');
});

// --- the pre-checks (before the money) ----------------------------------------------------------

it('refuses Payme CheckPerform / Create and Click Prepare on a pending order whose tier is no longer the live one (-31053 / -9), without opening a transaction', function (): void {
    // The refused orders stay pending until their TTL (nothing was paid, so nothing sweeps them) and still
    // count against the pending limit: room for the two renewals below.
    config()->set('billing.checkout.max_pending_per_subscriber', 4);
    $tutorPayme = coOrder($this->org, 'tutor');
    $tutorClick = coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    app(SubscriptionService::class)->grantManual($this->org, Plan::Pro, CarbonImmutable::now()->addDays(365), $this->admin, 'invoice paid', tier: 'center_m');

    expect(app(OrganizationOrderGuard::class)->refusal($tutorPayme, CarbonImmutable::now()))->toBe(OrganizationOrderGuard::REFUSAL_TIER_CHANGED);

    coExpectPaymeError(coPayme('CheckPerformTransaction', $tutorPayme), PaymeRpcException::TIER_CHANGED);
    coExpectPaymeError(coPayme('CreateTransaction', $tutorPayme), PaymeRpcException::TIER_CHANGED);

    $prepare = coClickPrepare($tutorClick);
    expect($prepare['error'])->toBe(ClickException::TRANSACTION_CANCELED)
        ->and($prepare['error_note'])->toBe(ClickException::note(ClickException::TRANSACTION_CANCELED));

    // The orders themselves stay pending (the sweep only runs on a payment); nothing was opened or paid.
    expect($tutorPayme->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($tutorClick->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and(ProviderTransaction::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);

    // A renewal of the live tier passes every pre-check.
    $renewal = coOrder($this->org, 'center_m');
    expect(coPayme('CheckPerformTransaction', $renewal)['result']['allow'] ?? null)->toBeTrue()
        ->and(coPayme('CreateTransaction', $renewal)['result']['state'] ?? null)->toBe(1);
    $renewalClick = coOrder($this->org, 'center_m', SubscriptionProvider::Click);
    expect(coClickPrepare($renewalClick, '777')['error'])->toBe(ClickException::SUCCESS);
});

it('refuses every pre-check for an order of a suspended organization (-31054 / -9)', function (): void {
    $payme = coOrder($this->org, 'tutor');
    $click = coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    $this->org->forceFill(['status' => 'suspended'])->save();

    expect(app(OrganizationOrderGuard::class)->refusal($payme, CarbonImmutable::now()))->toBe(OrganizationOrderGuard::REFUSAL_ORGANIZATION_SUSPENDED);

    coExpectPaymeError(coPayme('CheckPerformTransaction', $payme), PaymeRpcException::ORGANIZATION_SUSPENDED);
    coExpectPaymeError(coPayme('CreateTransaction', $payme), PaymeRpcException::ORGANIZATION_SUSPENDED);
    expect(coClickPrepare($click)['error'])->toBe(ClickException::TRANSACTION_CANCELED)
        ->and(ProviderTransaction::query()->count())->toBe(0);

    // Lifted again: the same orders are payable.
    $this->org->forceFill(['status' => 'active'])->save();
    expect(coPayme('CheckPerformTransaction', $payme)['result']['allow'] ?? null)->toBeTrue()
        ->and(coClickPrepare($click)['error'])->toBe(ClickException::SUCCESS);
});

it('exposes both new Payme codes with the three required languages', function (): void {
    foreach ([PaymeRpcException::tierChanged(), PaymeRpcException::organizationSuspended()] as $e) {
        $error = $e->toArray();
        expect($error['data'])->toBe('order_id')
            ->and(array_keys($error['message']))->toBe(['ru', 'uz', 'en']);
    }
    expect(PaymeRpcException::tierChanged()->rpcCode)->toBe(-31053)
        ->and(PaymeRpcException::organizationSuspended()->rpcCode)->toBe(-31054)
        ->and(PaymeRpcException::forRefusal('expired')->rpcCode)->toBe(PaymeRpcException::ORDER_NOT_PAYABLE);
});

it('leaves learner orders alone: the guard never refuses them and a learner payment cancels nothing', function (): void {
    $learner = Learner::factory()->create();
    $paymeOrder = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, CheckoutService::VIA_TWA);
    $clickOrder = app(CheckoutService::class)->create($learner, Plan::Pro, 'year', SubscriptionProvider::Click, null, CheckoutService::VIA_TWA);
    $orgOrder = coOrder($this->org, 'tutor');

    expect(app(OrganizationOrderGuard::class)->refusal($paymeOrder, CarbonImmutable::now()))->toBeNull()
        ->and(coPayme('CheckPerformTransaction', $paymeOrder)['result']['allow'] ?? null)->toBeTrue()
        ->and(coClickPrepare($clickOrder)['error'])->toBe(ClickException::SUCCESS);

    coPay($paymeOrder, 'learner-1');

    expect($clickOrder->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($orgOrder->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and(Subscription::query()->sole()->subscriber_type)->toBe('learner');
});

// --- the backstop: an order the provider is mid-payment on -------------------------------------

it('does not cancel an order with an open provider transaction younger than the timeout; its late payment is credited and flagged tier_mismatch', function (): void {
    Log::spy();
    $tutorPayme = coOrder($this->org, 'tutor');
    $tutorClick = coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    $centerM = coOrder($this->org, 'center_m');

    // Payme has CreateTransaction'd the Tutor order and Click has Prepare'd the other: the money may be on its way.
    expect(coPayme('CreateTransaction', $tutorPayme)['result']['state'] ?? null)->toBe(1);
    $prepare = coClickPrepare($tutorClick);
    expect($prepare['error'])->toBe(ClickException::SUCCESS);

    coPay($centerM, 'center-m-2');

    expect($tutorPayme->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($tutorClick->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING);

    // PerformTransaction / Complete arrive: the money is taken, so they are credited — the period is extended,
    // Center M and its seats stay, each order is flagged for the platform.
    expect(coPayme('PerformTransaction', $tutorPayme)['result']['state'] ?? null)->toBe(2);
    $complete = app(ClickMerchantService::class)->complete(ClickFixtures::complete($tutorClick, (int) $prepare['merchant_prepare_id']));
    expect($complete['error'])->toBe(ClickException::SUCCESS);

    $sub = Subscription::query()->sole();
    expect($sub->tier)->toBe('center_m')
        ->and($sub->seats_limit)->toBe(300)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(90)->toIso8601String())
        ->and($tutorPayme->refresh()->meta['tier_mismatch'])->toBeTrue()
        ->and($tutorClick->refresh()->meta['tier_mismatch'])->toBeTrue()
        ->and(Payment::query()->where('status', 'succeeded')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', SubscriptionService::ACTION_TIER_MISMATCH)->count())->toBe(2);
});

it('cancels an order whose open transaction is older than its provider timeout (dead at the provider)', function (): void {
    $tutor = coOrder($this->org, 'tutor');
    $centerM = coOrder($this->org, 'center_m');
    ProviderTransaction::factory()->create(['provider' => 'payme', 'payment_order_id' => $tutor->id, 'state' => ProviderTransaction::STATE_CREATED, 'created_at_provider' => CarbonImmutable::now()->subHours(13)]);

    coPay($centerM, 'center-m-3');

    expect($tutor->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($tutor->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED);
});

it('still credits a charge that reaches a tier_changed order (money already taken): paid_after_cancel + tier_mismatch, never lost', function (): void {
    Log::spy();
    $tutor = coOrder($this->org, 'tutor');
    $centerM = coOrder($this->org, 'center_m');
    coPay($centerM, 'center-m-4');
    expect($tutor->refresh()->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED);

    $payment = coPay($tutor, 'late-tutor');

    $sub = Subscription::query()->sole();
    $tutor->refresh();
    expect($payment->status)->toBe(Payment::STATUS_SUCCEEDED)
        ->and($tutor->status)->toBe(PaymentOrder::STATUS_PAID)
        ->and($tutor->meta['paid_after_cancel'])->toBeTrue()
        ->and($tutor->meta['tier_mismatch'])->toBeTrue()
        ->and($tutor->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_TIER_CHANGED)
        ->and($sub->tier)->toBe('center_m')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(60)->toIso8601String());
    Log::shouldHaveReceived('warning')->with('billing.canceled_order_paid', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $tutor->id && $ctx['canceled_reason'] === 'tier_changed'))->once();
    Log::shouldHaveReceived('warning')->with('billing.org_tier_mismatch', Mockery::any())->once();
});

it('a canceled order no longer counts against the pending limit and a fresh order of the live tier can be opened', function (): void {
    coOrder($this->org, 'tutor');
    coOrder($this->org, 'tutor', SubscriptionProvider::Click);
    $centerM = coOrder($this->org, 'center_m');
    coPay($centerM, 'center-m-5');

    $renewal = coOrder($this->org, 'center_m');
    expect($renewal->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and(PaymentOrder::query()->where('status', PaymentOrder::STATUS_PENDING)->count())->toBe(1);
});
