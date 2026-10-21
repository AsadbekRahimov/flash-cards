<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\ProviderTransactionException;
use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\ProviderTransactionService;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeBillingProvider;

/*
 * Offer reservation (docs/13 "Реализация 2B.7a"): an open offer is held by at most one pending order;
 * a new discounted checkout moves the hold (the old order is canceled with `offer_moved`) unless the
 * provider is mid-payment on it (`offer_busy`); the first successful payment uses the offer up and
 * releases any other holder. A charge that was already in flight on an offer-canceled order is
 * still credited — the double discount is logged, the money is never lost. The real races are in
 * BillingConcurrencyTest (PostgreSQL).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function orLearner(): Learner
{
    $learner = Learner::factory()->create();
    Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);

    return $learner;
}

function orOffer(Learner $learner): Offer
{
    return Offer::query()->where('learner_id', $learner->id)->sole();
}

function orCheckout(Learner $learner, SubscriptionProvider $provider, ?Offer $offer = null): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, 'month', $provider, null, 'twa', $offer);
}

function orCheckoutFailure(callable $call): CheckoutException
{
    try {
        $call();
    } catch (CheckoutException $e) {
        return $e;
    }

    throw new RuntimeException('Expected CheckoutException');
}

/** Pending orders of the learner that carry the offer. */
function orHolders(Offer $offer): int
{
    return PaymentOrder::query()->where('offer_id', $offer->id)->where('status', PaymentOrder::STATUS_PENDING)->count();
}

it('moves the discount to the newest order: the previous holder is canceled with offer_moved and one pending order carries the offer', function (): void {
    $learner = orLearner();
    $offer = orOffer($learner);

    $a = orCheckout($learner, SubscriptionProvider::Payme);
    $b = orCheckout($learner, SubscriptionProvider::TelegramStars);

    expect($a->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($a->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_OFFER_MOVED)
        ->and($a->meta['offer_moved_to_order_id'])->toBe($b->id)
        ->and($a->canceledForOffer())->toBeTrue()
        ->and($a->amount)->toBe(2_030_000) // the discounted price stays on the record: that is what the provider was told
        ->and($b->offer_id)->toBe($offer->id)
        ->and($b->amount)->toBe(105)
        ->and($b->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($offer->refresh()->isOpenAt(CarbonImmutable::now()))->toBeTrue()
        ->and(orHolders($offer))->toBe(1);

    // And again: the hold moves on, never two holders at once.
    $c = orCheckout($learner, SubscriptionProvider::Payme);
    expect($b->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($b->meta['offer_moved_to_order_id'])->toBe($c->id)
        ->and($c->offer_id)->toBe($offer->id)
        ->and(orHolders($offer))->toBe(1);

    // An order without the offer is not a holder and is left alone.
    $plain = PaymentOrder::factory()->create(['subscriber_id' => $learner->id]);
    orCheckout($learner, SubscriptionProvider::TelegramStars);
    expect($plain->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and(orHolders($offer))->toBe(1);
});

it('does not count the holder it is about to cancel against the pending-order limit', function (): void {
    config()->set('billing.checkout.max_pending_per_subscriber', 2);
    $learner = orLearner();
    $offer = orOffer($learner);

    $holder = orCheckout($learner, SubscriptionProvider::Payme);
    PaymentOrder::factory()->create(['subscriber_id' => $learner->id]);

    // Two pending orders already — but the holder is replaced, not added to.
    $moved = orCheckout($learner, SubscriptionProvider::TelegramStars);
    expect($moved->offer_id)->toBe($offer->id)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and(PaymentOrder::query()->where('subscriber_id', $learner->id)->where('status', PaymentOrder::STATUS_PENDING)->count())->toBe(2);

    PaymentOrder::factory()->create(['subscriber_id' => $learner->id]);
    $e = orCheckoutFailure(fn () => orCheckout($learner, SubscriptionProvider::Payme));
    expect($e->errorCode)->toBe(CheckoutException::TOO_MANY_PENDING);
});

it('refuses to move the discount while the provider is mid-payment on the holder (offer_busy), and moves it once that transaction is stale or closed', function (): void {
    $learner = orLearner();
    $offer = orOffer($learner);
    $holder = orCheckout($learner, SubscriptionProvider::Payme);

    // Payme CreateTransaction happened: the money may be on its way to PerformTransaction.
    $open = ProviderTransaction::factory()->create(['payment_order_id' => $holder->id, 'amount' => $holder->amount]);

    $e = orCheckoutFailure(fn () => orCheckout($learner, SubscriptionProvider::TelegramStars));
    expect($e->errorCode)->toBe(CheckoutException::OFFER_BUSY)
        ->and($e->httpStatus())->toBe(409)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and(PaymentOrder::query()->where('subscriber_id', $learner->id)->count())->toBe(1);

    // A transaction older than the provider timeout is dead (Payme cancels it with reason 4): the hold moves.
    $open->forceFill(['provider_time' => CarbonImmutable::now()->subHours(13)->getTimestampMs(), 'created_at_provider' => CarbonImmutable::now()->subHours(13)])->save();
    $moved = orCheckout($learner, SubscriptionProvider::TelegramStars);
    expect($moved->offer_id)->toBe($offer->id)->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED);

    // A canceled (state -1) transaction does not hold the order either.
    ProviderTransaction::factory()->canceled()->create(['payment_order_id' => $moved->id, 'provider' => 'telegram_stars', 'amount' => $moved->amount]);
    $again = orCheckout($learner, SubscriptionProvider::Payme);
    expect($again->offer_id)->toBe($offer->id)->and($moved->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED);
});

it('refuses the moved order at the provider pre-checks: Stars pre_checkout_query and a Payme / Click transaction open', function (): void {
    $learner = orLearner();
    $stars = orCheckout($learner, SubscriptionProvider::TelegramStars);
    $payme = orCheckout($learner, SubscriptionProvider::Payme);   // moves the hold: $stars is canceled
    orCheckout($learner, SubscriptionProvider::TelegramStars);    // moves again: $payme is canceled

    $verdict = app(StarsPreCheckoutGuard::class)->check([
        'id' => 'q1',
        'from' => ['id' => $learner->telegram_user_id, 'language_code' => 'ru'],
        'currency' => 'XTR',
        'total_amount' => $stars->amount,
        'invoice_payload' => $stars->token,
    ]);
    expect($verdict->ok)->toBeFalse()->and($verdict->reason)->toBe(StarsPreCheckoutGuard::REASON_ORDER_CLOSED);

    $open = fn () => app(ProviderTransactionService::class)->open(SubscriptionProvider::Payme, 'tx-moved', $payme->token, $payme->amount, null, []);
    expect($open)->toThrow(ProviderTransactionException::class, 'canceled');
});

it('honours a charge that was already in flight on a moved order: credited in full, the offer used by it, the new holder released', function (): void {
    Log::spy();
    $learner = orLearner();
    $offer = orOffer($learner);
    $a = orCheckout($learner, SubscriptionProvider::TelegramStars);
    $b = orCheckout($learner, SubscriptionProvider::Payme);
    expect($a->refresh()->canceledForOffer())->toBeTrue();

    // Telegram had already answered our pre_checkout OK: successful_payment arrives for the canceled order.
    $payment = app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($a, eventId: 'in-flight'));

    expect($payment->status)->toBe(Payment::STATUS_SUCCEEDED)
        ->and($payment->amount)->toBe(105)
        ->and($a->refresh()->status)->toBe(PaymentOrder::STATUS_PAID)
        ->and($a->meta['paid_after_cancel'])->toBeTrue()
        ->and($a->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_OFFER_MOVED)
        ->and($a->subscription_id)->not->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and($offer->refresh()->redeemed_at)->not->toBeNull()
        ->and($offer->payment_order_id)->toBe($a->id)
        ->and(ProductEvent::query()->where('name', 'offer.redeemed')->count())->toBe(1)
        ->and(isset($a->meta['offer_double_discount']))->toBeFalse();

    // The order the discount had moved to is released after commit: it must not pay with the discount too.
    expect($b->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($b->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_OFFER_REDEEMED)
        ->and($b->meta['offer_redeemed_by_order_id'])->toBe($a->id)
        ->and(orHolders($offer))->toBe(0);

    Log::shouldHaveReceived('warning')->with('billing.offer_canceled_order_paid', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $a->id))->once();
    Log::shouldNotHaveReceived('error');

    // Used up: the next checkout is at the list price (the Stars renewal that just started would block any order).
    Subscription::query()->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()]);
    expect(orCheckout($learner->refresh(), SubscriptionProvider::Payme)->offer_id)->toBeNull();
});

it('credits a second in-flight charge on a released holder in full and records the double discount instead of losing the money', function (): void {
    Log::spy();
    $learner = orLearner();
    $offer = orOffer($learner);
    $a = orCheckout($learner, SubscriptionProvider::TelegramStars);
    $b = orCheckout($learner, SubscriptionProvider::Payme);
    app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($a, eventId: 'first'));
    expect($b->refresh()->canceledForOffer())->toBeTrue();

    // Payme had taken the money for B before it was released: PerformTransaction arrives anyway.
    $second = app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($b, eventId: 'second'));

    expect($second->status)->toBe(Payment::STATUS_SUCCEEDED)
        ->and($second->amount)->toBe(2_030_000)
        ->and($b->refresh()->status)->toBe(PaymentOrder::STATUS_PAID)
        ->and($b->meta['offer_double_discount'])->toBeTrue()
        ->and($b->meta['paid_after_cancel'])->toBeTrue()
        ->and($offer->refresh()->payment_order_id)->toBe($a->id)   // the offer still says who used it first
        ->and(ProductEvent::query()->where('name', 'offer.redeemed')->count())->toBe(1)
        ->and(Payment::query()->where('status', Payment::STATUS_SUCCEEDED)->count())->toBe(2);

    // Both periods are credited: 30 + 30 days.
    $sub = Subscription::query()->where('subscriber_id', $learner->id)->sole();
    expect($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(60)->toIso8601String());

    Log::shouldHaveReceived('error')->with('billing.offer_double_discount', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $b->id && $ctx['redeemed_by_order_id'] === $a->id))->once();
});

it('still refuses a charge on an order canceled for any other reason and a renewal on a moved order', function (): void {
    $learner = orLearner();
    $moved = orCheckout($learner, SubscriptionProvider::TelegramStars);
    orCheckout($learner, SubscriptionProvider::Payme);
    $byProvider = PaymentOrder::factory()->payme()->create(['subscriber_id' => $learner->id, 'status' => PaymentOrder::STATUS_CANCELED]);

    $renewal = fn () => app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($moved, renewal: true, eventId: 'renewal'));
    expect($renewal)->toThrow(PaymentApplyException::class);
    $other = fn () => app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($byProvider, eventId: 'other'));
    expect($other)->toThrow(PaymentApplyException::class);

    expect(Payment::query()->where('status', Payment::STATUS_FAILED)->count())->toBe(2)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(orOffer($learner)->refresh()->redeemed_at)->toBeNull();
});

it('does not start a discounted order once the holder got paid: an explicit offer is refused, an implicit one is not applied', function (): void {
    $learner = orLearner();
    $offer = orOffer($learner);
    $holder = orCheckout($learner, SubscriptionProvider::Payme);
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($holder));

    $e = orCheckoutFailure(fn () => orCheckout($learner, SubscriptionProvider::TelegramStars, $offer->refresh()));
    expect($e->errorCode)->toBe(CheckoutException::OFFER_NOT_APPLICABLE);

    $next = orCheckout($learner, SubscriptionProvider::Payme);
    expect($next->offer_id)->toBeNull()->and($next->amount)->toBe(2_900_000)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_PAID);
});

it('leaves an expired or user-canceled holder alone and does not expire a holder that is mid-payment', function (): void {
    $learner = orLearner();
    $offer = orOffer($learner);
    $holder = orCheckout($learner, SubscriptionProvider::Payme);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay()->addMinute());
    expect(app(CheckoutService::class)->expirePending())->toBe(1)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_EXPIRED)
        ->and($holder->canceledForOffer())->toBeFalse()
        ->and(orHolders($offer))->toBe(0);

    // The offer is free again (until its own deadline) and the expired order is not touched by the next checkout.
    $again = orCheckout($learner, SubscriptionProvider::TelegramStars);
    expect($again->offer_id)->toBe($offer->id)->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_EXPIRED);
});
