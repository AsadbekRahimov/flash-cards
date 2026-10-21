<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PaymentApplier;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeBillingProvider;

/*
 * PaymentApplier (docs/13 §2.1): provider events -> ledger + subscription, idempotent by
 * `payments (provider, provider_event_id)`; the lock order is exercised for real in
 * BillingConcurrencyTest (PostgreSQL).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.grace_days', 3);
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function applierOrder(Learner $learner, FakeBillingProvider $via, string $period = 'month'): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $via->key(), null, 'twa');
}

function applierFailure(callable $call): PaymentApplyException
{
    try {
        $call();
    } catch (PaymentApplyException $e) {
        return $e;
    }

    throw new RuntimeException('Expected PaymentApplyException');
}

it('credits a first charge: active subscription for the order period, ledger row, order paid, event, cache dropped', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);
    $order = applierOrder($learner, $this->stars);
    $now = CarbonImmutable::now();

    $payment = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'tg_charge_1'));

    expect($payment->status)->toBe('succeeded')
        ->and($payment->kind)->toBe('charge')
        ->and($payment->provider)->toBe('telegram_stars')
        ->and($payment->provider_event_id)->toBe('tg_charge_1')
        ->and($payment->provider_charge_id)->toBe('tg_charge_1')
        ->and($payment->amount)->toBe($order->amount)
        ->and($payment->currency)->toBe('XTR')
        ->and($payment->subscriber_type)->toBe('learner')
        ->and($payment->subscriber_id)->toBe($learner->id)
        ->and($payment->payment_order_id)->toBe($order->id)
        ->and($payment->period_starts_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($payment->period_ends_at?->toIso8601String())->toBe($now->addDays(30)->toIso8601String());

    $sub = Subscription::query()->sole();
    expect($payment->subscription_id)->toBe($sub->id)
        ->and($sub->status)->toBe('active')
        ->and($sub->provider)->toBe('telegram_stars')
        ->and($sub->provider_ref)->toBe('tg_charge_1')
        ->and($sub->meta['stars']['charge_id'])->toBe('tg_charge_1')
        ->and($sub->meta['history'][0]['action'])->toBe('charge')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(30)->toIso8601String());

    expect($order->refresh()->status)->toBe('paid')
        ->and($order->paid_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and($order->subscription_id)->toBe($sub->id);

    expect(ProductEvent::query()->where('name', 'payment.succeeded')->sole()->props['payment_order_id'])->toBe($order->id)
        ->and(ProductEvent::query()->where('name', 'subscription.granted')->sole()->props['provider'])->toBe('telegram_stars')
        ->and(Cache::has(EntitlementResolver::cacheKey($learner->id)))->toBeFalse();

    $e = $resolver->for($learner);
    expect($e->plan)->toBe(Plan::Pro)->and($e->until?->toIso8601String())->toBe($now->addDays(33)->toIso8601String()); // Stars: + grace
});

it('ignores a repeated event without side effects and hands back the first ledger row', function (): void {
    $learner = Learner::factory()->create();
    $order = applierOrder($learner, $this->stars);
    $applier = app(PaymentApplier::class);

    $first = $applier->applyCharge($this->stars->emitCharge($order, eventId: 'dup'));
    $sub = Subscription::query()->sole();

    try {
        $applier->applyCharge($this->stars->emitCharge($order, eventId: 'dup'));
        $this->fail('expected DuplicatePaymentException');
    } catch (DuplicatePaymentException $e) {
        expect($e->payment->id)->toBe($first->id)->and($e->errorCode)->toBe('duplicate_payment')->and($e->httpStatus())->toBe(409);
    }

    expect(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and($sub->refresh()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and(count($sub->meta['history']))->toBe(1)
        ->and(ProductEvent::query()->where('name', 'payment.succeeded')->count())->toBe(1);
});

it('records an amount or currency mismatch as a failed payment and leaves the subscription and the order alone', function (): void {
    Log::spy();
    $learner = Learner::factory()->create();
    $order = applierOrder($learner, $this->stars);

    $payment = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'short', amount: $order->amount - 1));

    expect($payment->status)->toBe('failed')
        ->and($payment->raw['failure_reason'])->toBe('amount_mismatch')
        ->and($payment->subscription_id)->toBeNull()
        ->and(Subscription::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe('pending')
        ->and(ProductEvent::query()->where('name', 'payment.failed')->sole()->props['reason'])->toBe('amount_mismatch')
        ->and(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Free);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'billing.amount_mismatch')->once();

    // The failed row still owns its event id: a replay is a duplicate, not a second chance.
    expect(fn () => app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'short')))->toThrow(DuplicatePaymentException::class);

    // A correct event for the same order still goes through.
    $ok = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'full'));
    expect($ok->status)->toBe('succeeded')->and($order->refresh()->status)->toBe('paid');

    $other = Learner::factory()->create(); // (a Payme order next to the live Stars one would be checkout_conflict)
    $wrongCurrency = app(PaymentApplier::class)->applyCharge($this->payme->emitCharge(applierOrder($other, $this->payme), eventId: 'eur', currency: 'EUR'));
    expect($wrongCurrency->status)->toBe('failed')->and(Subscription::query()->count())->toBe(1);
});

it('extends a live subscription from max(current end, now) and keeps the later end when the provider reports an earlier one', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::now();
    $applier = app(PaymentApplier::class);

    // A manual grant ending in 10 days + a Payme month -> ends in 40 days; provider of the last payment wins.
    $live = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'manual', 'current_period_ends_at' => $now->addDays(10)]);
    $payment = $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'p1'));
    expect($live->refresh()->current_period_ends_at?->toIso8601String())->toBe($now->addDays(40)->toIso8601String())
        ->and($live->provider)->toBe('payme')
        ->and($payment->period_starts_at?->toIso8601String())->toBe($now->addDays(10)->toIso8601String())
        ->and($payment->period_ends_at?->toIso8601String())->toBe($now->addDays(40)->toIso8601String())
        ->and(Subscription::query()->count())->toBe(1);

    // Stars subscription on top of the Payme period: Telegram says it expires in 30 days, our period
    // runs 40 -> keep 40 (docs/13 §4.4 "конец = max").
    $applier->applyCharge($this->stars->emitCharge(applierOrder($learner, $this->stars), eventId: 's1', periodEndsAt: $now->addDays(30)));
    expect($live->refresh()->current_period_ends_at?->toIso8601String())->toBe($now->addDays(40)->toIso8601String())
        ->and($live->provider)->toBe('telegram_stars')
        ->and($live->meta['stars']['charge_id'])->toBe('s1');

    // A provider end later than ours wins.
    $applier->applyCharge($this->stars->emitCharge(applierOrder($learner, $this->stars), eventId: 's2', periodEndsAt: $now->addDays(70)));
    expect($live->refresh()->current_period_ends_at?->toIso8601String())->toBe($now->addDays(70)->toIso8601String());

    // Period already over (scheduler late): the new period starts now, not at the old end.
    $live->update(['current_period_ends_at' => $now->subDays(5), 'provider' => 'payme']);
    $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'p2'));
    expect($live->refresh()->current_period_ends_at?->toIso8601String())->toBe($now->addDays(30)->toIso8601String());
});

it('re-activates a canceled or past_due subscription on a Stars renewal and keeps the first charge id', function (): void {
    $learner = Learner::factory()->create();
    $now = CarbonImmutable::now();
    $applier = app(PaymentApplier::class);
    $order = applierOrder($learner, $this->stars);
    $applier->applyCharge($this->stars->emitCharge($order, eventId: 'first'));
    $sub = Subscription::query()->sole();

    // Grace: the period ended, Telegram retried and charged.
    $sub->update(['status' => 'past_due', 'current_period_ends_at' => $now->subDay(), 'grace_until' => $now->addDays(2)]);
    $renewal = $applier->applyCharge($this->stars->emitCharge($order, renewal: true, periodEndsAt: $now->addDays(29), eventId: 'second'));

    expect($renewal->kind)->toBe('renewal')
        ->and($order->refresh()->status)->toBe('paid') // the Stars order stays the anchor of every renewal
        ->and($sub->refresh()->status)->toBe('active')
        ->and($sub->grace_until)->toBeNull()
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(29)->toIso8601String())
        ->and($sub->provider_ref)->toBe('second')
        ->and($sub->meta['stars']['charge_id'])->toBe('first')
        ->and($sub->meta['history'][1]['action'])->toBe('renewal');

    // Canceled, then the user resumed in Telegram and a renewal arrived.
    $sub->update(['status' => 'canceled', 'canceled_at' => $now]);
    $applier->applyCharge($this->stars->emitCharge($order, renewal: true, periodEndsAt: $now->addDays(59), eventId: 'third'));
    expect($sub->refresh()->status)->toBe('active')->and($sub->canceled_at)->toBeNull();
});

it('refuses a non-renewal charge on a paid order and any charge on a dead or foreign order, keeping a failed ledger row as evidence', function (): void {
    $learner = Learner::factory()->create();
    $applier = app(PaymentApplier::class);

    $paid = applierOrder($learner, $this->stars);
    $applier->applyCharge($this->stars->emitCharge($paid, eventId: 'ok'));

    $e = applierFailure(fn () => $applier->applyCharge($this->stars->emitCharge($paid, eventId: 'again')));
    expect($e->errorCode)->toBe(PaymentApplyException::ORDER_MISMATCH)->and($e->httpStatus())->toBe(409)
        ->and(Payment::query()->where('provider_event_id', 'again')->sole()->status)->toBe('failed')
        ->and(Payment::query()->where('provider_event_id', 'again')->sole()->raw['failure_reason'])->toBe('order_already_paid');

    $expired = PaymentOrder::factory()->expired()->create(['subscriber_id' => $learner->id]);
    $e = applierFailure(fn () => $applier->applyCharge($this->stars->emitCharge($expired, eventId: 'late')));
    expect($e->errorCode)->toBe(PaymentApplyException::ORDER_EXPIRED)->and($e->httpStatus())->toBe(410)
        ->and(Payment::query()->where('provider_event_id', 'late')->sole()->raw['failure_reason'])->toBe('order_expired');

    $payme = applierOrder(Learner::factory()->create(), $this->payme);
    $e = applierFailure(fn () => $applier->applyCharge($this->stars->emitCharge($payme, eventId: 'wrong-provider', amount: $payme->amount, currency: 'UZS')));
    expect($e->errorCode)->toBe(PaymentApplyException::ORDER_MISMATCH)
        ->and(Payment::query()->where('provider_event_id', 'wrong-provider')->sole()->raw['failure_reason'])->toBe('provider_mismatch')
        ->and($payme->refresh()->status)->toBe('pending');

    $e = applierFailure(fn () => $applier->applyCharge(new ChargeEvent(SubscriptionProvider::TelegramStars, 'ghost', 'ghost', 'no-such-token', 150, 'XTR', false, null, CarbonImmutable::now())));
    expect($e->errorCode)->toBe(PaymentApplyException::ORDER_NOT_FOUND)->and($e->httpStatus())->toBe(404)
        ->and(Payment::query()->where('provider_event_id', 'ghost')->exists())->toBeFalse();

    // Only the one real credit happened.
    expect(Subscription::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and(ProductEvent::query()->where('name', 'payment.failed')->count())->toBe(3);
});

it('redeems the applied offer exactly once', function (): void {
    $learner = Learner::factory()->create();
    $offer = Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30]);
    $order = applierOrder($learner, $this->stars);
    expect($order->offer_id)->toBe($offer->id)->and($order->amount)->toBe(105)->and($order->list_amount)->toBe(150);

    // The provider reports the discounted amount — that is what the order says.
    app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, eventId: 'with-offer'));

    expect($offer->refresh()->redeemed_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($offer->payment_order_id)->toBe($order->id);

    // The next order is full price: the offer is gone.
    $next = applierOrder($learner, $this->stars);
    expect($next->offer_id)->toBeNull()->and($next->amount)->toBe(150);
});

it('credits an organization order with its tier and drops the cache of the sponsored members', function (): void {
    $org = Organization::factory()->create();
    // Joined before the center paid: no seat yet (waitlisted). The payment's `reseat` after commit seats them (docs/13 §2.3).
    $member = Learner::factory()->seatedIn($org, 'waitlisted')->create();
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($member)->plan)->toBe(Plan::Free);

    $order = app(CheckoutService::class)->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'center_s', 'org_panel');
    expect($order->amount)->toBe(34_900_000)->and($order->tier)->toBe('center_s')->and($order->currency)->toBe('UZS');

    $payment = app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order, eventId: 'org-pay'));

    $sub = Subscription::query()->sole();
    expect($payment->subscriber_type)->toBe('organization')
        ->and($sub->subscriber_type)->toBe('organization')
        ->and($sub->subscriber_id)->toBe($org->id)
        ->and($sub->plan)->toBe('pro')
        ->and($sub->tier)->toBe('center_s')
        ->and($sub->seats_limit)->toBe(100)
        ->and(Cache::has(EntitlementResolver::cacheKey($member->id)))->toBeFalse()
        ->and($resolver->for($member)->source)->toBe("sponsor:organization:{$org->id}")
        ->and(ProductEvent::query()->where('name', 'payment.succeeded')->sole()->learner_id)->toBeNull();

    // Another tier is not sold online while the subscription is live (2B.8, OrgTierChangeTest); a renewal
    // of the same tier extends the period and keeps the seats.
    expect(fn () => app(CheckoutService::class)->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'center_m', 'org_panel'))
        ->toThrow(CheckoutException::class, 'contact support');
    $renewal = app(CheckoutService::class)->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'center_s', 'org_panel');
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($renewal, eventId: 'org-pay-2'));
    expect($sub->refresh()->tier)->toBe('center_s')->and($sub->seats_limit)->toBe(100)
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(60)->toIso8601String());
});

it('refunds the current period: refund row, charge marked refunded, subscription ended now, audit when an admin did it', function (): void {
    $learner = Learner::factory()->create();
    $admin = User::factory()->create();
    $applier = app(PaymentApplier::class);
    $resolver = app(EntitlementResolver::class);
    $order = applierOrder($learner, $this->stars);
    $charge = $applier->applyCharge($this->stars->emitCharge($order, eventId: 'paid'));
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $refund = $applier->applyRefund($this->stars->emitRefund($charge, eventId: 'refund-1'), $admin);

    expect($refund->kind)->toBe('refund')
        ->and($refund->status)->toBe('succeeded')
        ->and($refund->refunded_payment_id)->toBe($charge->id)
        ->and($refund->amount)->toBe($charge->amount)
        ->and($refund->subscription_id)->toBe($charge->subscription_id)
        ->and($charge->refresh()->status)->toBe('refunded');

    $sub = Subscription::query()->sole();
    expect($sub->status)->toBe('expired')
        ->and($sub->ended_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($sub->meta['history'][1]['reason'])->toBe('refund')
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);

    $audit = AuditLog::query()->where('action', 'payment.refunded')->sole();
    expect($audit->user_id)->toBe($admin->id)->and($audit->entity_id)->toBe($charge->id)->and($audit->meta['subscription_ended'])->toBeTrue();
    expect(ProductEvent::query()->where('name', 'payment.refunded')->sole()->props['subscription_ended'])->toBeTrue()
        ->and(ProductEvent::query()->where('name', 'subscription.expired')->sole()->props['reason'])->toBe('refund');

    // Replay -> duplicate; another refund event for the same charge -> already_refunded; unknown charge -> payment_not_found.
    expect(fn () => $applier->applyRefund($this->stars->emitRefund($charge, eventId: 'refund-1')))->toThrow(DuplicatePaymentException::class);
    $e = applierFailure(fn () => $applier->applyRefund($this->stars->emitRefund($charge, eventId: 'refund-2')));
    expect($e->errorCode)->toBe(PaymentApplyException::ALREADY_REFUNDED);
    $e = applierFailure(fn () => $applier->applyRefund($this->stars->emitRefund(Payment::factory()->make(['provider_charge_id' => 'nope', 'provider' => 'telegram_stars']), eventId: 'refund-3')));
    expect($e->errorCode)->toBe(PaymentApplyException::PAYMENT_NOT_FOUND)->and($e->httpStatus())->toBe(404);
    expect(Payment::query()->count())->toBe(2);
});

it('refunds a past period without touching the running subscription, and a provider refund needs no admin', function (): void {
    $learner = Learner::factory()->create();
    $applier = app(PaymentApplier::class);
    $now = CarbonImmutable::now();

    // Two Payme months: the first period is over, the second is running.
    CarbonImmutable::setTestNow($now->subDays(40));
    $first = $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'm1'));
    CarbonImmutable::setTestNow($now->subDays(10));
    $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'm2'));
    CarbonImmutable::setTestNow($now);
    $sub = Subscription::query()->sole();
    expect($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(20)->toIso8601String());

    $refund = $applier->applyRefund($this->payme->emitRefund($first, eventId: 'm1:cancel'));

    expect($refund->refunded_payment_id)->toBe($first->id)
        ->and($first->refresh()->status)->toBe('refunded')
        ->and($sub->refresh()->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(20)->toIso8601String())
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'payment.refunded')->sole()->props['subscription_ended'])->toBeFalse();
});

it('H4: refunding one of two consecutive paid months keeps the other — the second leaves the first, the first moves the end back a month', function (): void {
    $learner = Learner::factory()->create();
    $applier = app(PaymentApplier::class);
    $now = CarbonImmutable::now();
    $first = $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'm1'));
    $second = $applier->applyCharge($this->payme->emitCharge(applierOrder($learner, $this->payme), eventId: 'm2'));
    $sub = Subscription::query()->sole();
    expect($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(60)->toIso8601String());

    $refund = $applier->applyRefund($this->payme->emitRefund($second, eventId: 'm2:cancel'));

    expect($sub->refresh()->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(30)->toIso8601String())
        ->and($sub->meta['history'][array_key_last($sub->meta['history'])]['action'])->toBe('shortened')
        ->and(ProductEvent::query()->where('name', 'payment.refunded')->sole()->props['subscription_ended'])->toBeFalse()
        ->and(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Pro);

    // Refunding the remaining (first) month empties the subscription: the new end is now.
    $applier->applyRefund($this->payme->emitRefund($first, eventId: 'm1:cancel'));
    expect($sub->refresh()->status)->toBe('expired')->and(app(EntitlementResolver::class)->for($learner)->plan)->toBe(Plan::Free);

    // Other order: the FIRST of two months refunded — the end moves a month back, the subscription lives.
    $other = Learner::factory()->create();
    $a = $applier->applyCharge($this->payme->emitCharge(applierOrder($other, $this->payme), eventId: 'n1'));
    $applier->applyCharge($this->payme->emitCharge(applierOrder($other, $this->payme), eventId: 'n2'));
    $applier->applyRefund($this->payme->emitRefund($a, eventId: 'n1:cancel'));
    $otherSub = Subscription::query()->where('subscriber_id', $other->id)->sole();
    expect($otherSub->status)->toBe('active')->and($otherSub->current_period_ends_at?->toIso8601String())->toBe($now->addDays(30)->toIso8601String());
});

it('records an orphan charge (order lost, subscriber known) as failed, once', function (): void {
    $learner = Learner::factory()->create();
    $event = new ChargeEvent(SubscriptionProvider::TelegramStars, 'orphan-1', 'orphan-1', 'gone', 150, 'XTR', true, CarbonImmutable::now()->addDays(30), CarbonImmutable::now());

    $payment = app(PaymentApplier::class)->recordOrphan($event, $learner, 'order_missing');

    expect($payment->status)->toBe('failed')
        ->and($payment->kind)->toBe('renewal')
        ->and($payment->payment_order_id)->toBeNull()
        ->and($payment->subscriber_id)->toBe($learner->id)
        ->and($payment->raw['failure_reason'])->toBe('order_missing')
        ->and(Subscription::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'payment.failed')->sole()->props['reason'])->toBe('order_missing');

    expect(fn () => app(PaymentApplier::class)->recordOrphan($event, $learner, 'order_missing'))->toThrow(DuplicatePaymentException::class);
});
