<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\OfferService;
use App\Domain\Billing\Support\ProviderTransactionTimeout;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;

/*
 * "Being paid" is judged per provider ("Реализация 2B.8"): an open Payme transaction counts for 12 h
 * (Payme's own lifetime of CreateTransaction), an open Click Prepare only for
 * `billing.providers.click.transaction_timeout_ms` (30 min by default) — a Prepare that never got
 * its Complete must not hold a learner's discount or keep an expired order payable for half a day.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->click = FakeBillingProvider::register(SubscriptionProvider::Click);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function ptxOpen(PaymentOrder $order, int $minutesAgo): ProviderTransaction
{
    $at = CarbonImmutable::now()->subMinutes($minutesAgo);

    return ProviderTransaction::factory()->create([
        'provider' => $order->provider,
        'payment_order_id' => $order->id,
        'amount' => $order->amount,
        'provider_time' => $at->getTimestampMs(),
        'created_at_provider' => $at,
    ]);
}

it('reads the timeout per provider from config with the documented defaults; Stars has none', function (): void {
    expect(ProviderTransactionTimeout::ms(SubscriptionProvider::Payme))->toBe(43_200_000)
        ->and(ProviderTransactionTimeout::ms('click'))->toBe(1_800_000)
        ->and(ProviderTransactionTimeout::ms(SubscriptionProvider::TelegramStars))->toBeNull()
        ->and(ProviderTransactionTimeout::openSince(SubscriptionProvider::TelegramStars, CarbonImmutable::now()))->toBeNull()
        ->and(array_keys(ProviderTransactionTimeout::openSinceByProvider(CarbonImmutable::now())))->toBe(['payme', 'click'])
        ->and(ProviderTransactionTimeout::openSinceByProvider(CarbonImmutable::now())['click']->toIso8601String())->toBe(CarbonImmutable::now()->subMinutes(30)->toIso8601String());

    config()->set('billing.providers.click.transaction_timeout_ms', 600_000);
    expect(ProviderTransactionTimeout::ms('click'))->toBe(600_000);
});

it('holds an order for Payme up to 12 h and for Click only up to its own timeout', function (): void {
    $offers = app(OfferService::class);
    $now = CarbonImmutable::now();

    $paymeYoung = PaymentOrder::factory()->payme()->create();
    ptxOpen($paymeYoung, 11 * 60);
    $paymeStale = PaymentOrder::factory()->payme()->create();
    ptxOpen($paymeStale, 13 * 60);
    $clickYoung = PaymentOrder::factory()->click()->create();
    ptxOpen($clickYoung, 29);
    $clickStale = PaymentOrder::factory()->click()->create();
    ptxOpen($clickStale, 31);

    expect($offers->isBeingPaid($paymeYoung, $now))->toBeTrue()
        ->and($offers->isBeingPaid($paymeStale, $now))->toBeFalse()
        ->and($offers->isBeingPaid($clickYoung, $now))->toBeTrue()
        ->and($offers->isBeingPaid($clickStale, $now))->toBeFalse();
});

it('expires an order whose Click Prepare is older than the Click timeout while a Payme order with an 11 h old transaction stays payable', function (): void {
    $service = app(CheckoutService::class);
    $past = CarbonImmutable::now()->subMinute();

    $paymeLive = PaymentOrder::factory()->payme()->create(['expires_at' => $past]);
    ptxOpen($paymeLive, 11 * 60);
    $clickLive = PaymentOrder::factory()->click()->create(['expires_at' => $past]);
    ptxOpen($clickLive, 10);
    $clickStale = PaymentOrder::factory()->click()->create(['expires_at' => $past]);
    ptxOpen($clickStale, 31);
    $plain = PaymentOrder::factory()->click()->create(['expires_at' => $past]);

    expect($service->expirePending())->toBe(2)
        ->and($paymeLive->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($clickLive->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING)
        ->and($clickStale->refresh()->status)->toBe(PaymentOrder::STATUS_EXPIRED)
        ->and($plain->refresh()->status)->toBe(PaymentOrder::STATUS_EXPIRED);

    // Half an hour later the young Click Prepare is stale too; the Payme one is still within its 12 h.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));
    expect($service->expirePending())->toBe(1)
        ->and($clickLive->refresh()->status)->toBe(PaymentOrder::STATUS_EXPIRED)
        ->and($paymeLive->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING);
});

it('a stale Click Prepare no longer holds the discount: a new checkout moves the offer, a fresh one still refuses (offer_busy)', function (): void {
    $learner = Learner::factory()->create();
    $offer = Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $checkout = app(CheckoutService::class);

    $holder = $checkout->create($learner, Plan::Pro, 'month', SubscriptionProvider::Click, null, 'twa');
    $prepare = ptxOpen($holder, 5);

    try {
        $checkout->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
        $this->fail('Expected offer_busy');
    } catch (CheckoutException $e) {
        expect($e->errorCode)->toBe(CheckoutException::OFFER_BUSY);
    }

    // 31 minutes after Prepare, Click is not coming back with Complete: the hold moves.
    $stale = CarbonImmutable::now()->subMinutes(31);
    $prepare->forceFill(['provider_time' => $stale->getTimestampMs(), 'created_at_provider' => $stale])->save();

    $moved = $checkout->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($moved->offer_id)->toBe($offer->id)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_CANCELED)
        ->and($holder->meta['canceled_reason'])->toBe(PaymentOrder::CANCEL_REASON_OFFER_MOVED);
});
