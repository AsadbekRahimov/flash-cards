<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Providers\PaymeProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * PaymeProvider (docs/13 §2.2, §4.2): the registered adapter, who it serves, the checkout link
 * (`m;ac.order_id;a;l;c` in base64 — format to be confirmed in the sandbox, docs/13 §15) and the
 * capabilities it refuses (auto-renewal, refunds initiated by us).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('app.url', 'https://lexiflow.example.com');
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.merchant_id', '5e730e8e0b852a417aa49ceb');
    config()->set('billing.providers.payme.checkout_url', 'https://checkout.test.paycom.uz/');
    config()->set('billing.paywall.mode', 'purchase');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('is registered as the real Payme adapter and serves learners and organizations paying in UZS', function (): void {
    $provider = app(BillingProviderRegistry::class)->for(SubscriptionProvider::Payme);

    expect($provider)->toBeInstanceOf(PaymeProvider::class)
        ->and($provider->key())->toBe(SubscriptionProvider::Payme)
        ->and($provider->supports('learner', 'UZS'))->toBeTrue()
        ->and($provider->supports('organization', 'UZS'))->toBeTrue()
        ->and($provider->supports('learner', 'XTR'))->toBeFalse()
        ->and(app(BillingProviderRegistry::class)->isEnabled(SubscriptionProvider::Payme))->toBeTrue();

    config()->set('billing.providers.payme.enabled', false);
    expect(app(BillingProviderRegistry::class)->isEnabled(SubscriptionProvider::Payme))->toBeFalse();
});

it('builds the checkout link for a learner order: merchant, order token as account, tiyin amount, language and return URL', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    $link = $order->checkoutLink();

    expect($order->status)->toBe('pending')
        ->and($order->currency)->toBe('UZS')
        ->and($order->amount)->toBe((int) config('billing.prices.pro.month.UZS'))
        ->and($link)->toBeInstanceOf(CheckoutLink::class)
        ->and($link?->kind)->toBe(CheckoutLink::KIND_REDIRECT)
        ->and($link?->expiresAt->toIso8601String())->toBe(CarbonImmutable::instance($order->expires_at)->toIso8601String())
        ->and($link?->url)->toStartWith('https://checkout.test.paycom.uz/');

    $encoded = substr((string) $link?->url, strlen('https://checkout.test.paycom.uz/'));
    expect(base64_decode($encoded, true))->toBe(implode(';', [
        'm=5e730e8e0b852a417aa49ceb',
        'ac.order_id='.$order->token,
        'a='.$order->amount,
        'l=uz',
        'c=https://lexiflow.example.com/billing/return?order='.$order->token.'&lang=uz',
    ]));
});

it('uses the default language for an organization order and never trusts the request host for the return URL', function (): void {
    $organization = Organization::factory()->create();
    $provider = app(PaymeProvider::class);

    $order = PaymentOrder::factory()->forOrganization($organization)->create();
    $link = $provider->createCheckout($order);

    $params = (string) base64_decode(substr($link->url, strlen('https://checkout.test.paycom.uz/')), true);
    expect($params)->toContain(';l=ru;')
        ->and($params)->toContain('c=https://lexiflow.example.com/billing/return?order='.$order->token.'&lang=ru')
        ->and(PaymeProvider::returnUrl('tok', 'ru'))->toBe('https://lexiflow.example.com/billing/return?order=tok&lang=ru');

    // Not a Payme order / wrong currency.
    $stars = PaymentOrder::factory()->create();
    expect(fn () => $provider->createCheckout($stars))->toThrow(InvalidArgumentException::class);
});

it('refuses to sell without a merchant id and leaves the order failed', function (): void {
    config()->set('billing.providers.payme.merchant_id', '');
    $learner = Learner::factory()->create();

    expect(fn () => app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa'))
        ->toThrow(ProviderCapabilityException::class);
    expect(PaymentOrder::query()->sole()->status)->toBe('failed');
});

it('does not renew or refund by itself: those are ProviderCapabilityException (refunds come back through CancelTransaction)', function (): void {
    $provider = app(PaymeProvider::class);
    $subscription = Subscription::factory()->create(['provider' => 'payme']);
    $payment = Payment::factory()->create(['provider' => 'payme', 'provider_charge_id' => 'tx1']);

    expect(fn () => $provider->cancelAutoRenew($subscription))->toThrow(ProviderCapabilityException::class)
        ->and(fn () => $provider->resumeAutoRenew($subscription))->toThrow(ProviderCapabilityException::class)
        ->and(fn () => $provider->refund($payment))->toThrow(ProviderCapabilityException::class);
});
