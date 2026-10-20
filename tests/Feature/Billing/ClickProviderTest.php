<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Providers\ClickProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Support\BillingReturnUrl;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClickFixtures;

/*
 * ClickProvider (docs/13 §2.2, §4.3): the registered adapter, who it serves, the checkout link
 * (`service_id`, `merchant_id`, `amount` in UZS with two decimals, `transaction_param` = order token,
 * `return_url` on APP_URL — parameters to be confirmed in the Click test flow, docs/13 §15) and the
 * capabilities it refuses (auto-renewal, refunds initiated by us).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('app.url', 'https://lexiflow.example.com');
    config()->set('billing.paywall.mode', 'purchase');
    ClickFixtures::configure();
    config()->set('billing.providers.click.checkout_url', 'https://my.click.uz/services/pay');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('is registered as the real Click adapter and serves learners and organizations paying in UZS, sellable only when enabled', function (): void {
    $registry = app(BillingProviderRegistry::class);
    $provider = $registry->for(SubscriptionProvider::Click);

    expect($provider)->toBeInstanceOf(ClickProvider::class)
        ->and($provider->key())->toBe(SubscriptionProvider::Click)
        ->and($provider->supports('learner', 'UZS'))->toBeTrue()
        ->and($provider->supports('organization', 'UZS'))->toBeTrue()
        ->and($provider->supports('learner', 'XTR'))->toBeFalse()
        ->and($registry->isEnabled(SubscriptionProvider::Click))->toBeTrue();

    config()->set('billing.providers.click.enabled', false);
    expect($registry->isEnabled(SubscriptionProvider::Click))->toBeFalse()
        ->and($registry->enabled())->not->toContain(SubscriptionProvider::Click);
});

it('builds the checkout link for a learner order: service and merchant ids, UZS amount with two decimals, the token as transaction_param and the return URL', function (): void {
    config()->set('billing.prices.pro.month.UZS', 2_900_050);
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Click, null, 'twa');
    $link = $order->checkoutLink();

    expect($order->status)->toBe('pending')
        ->and($order->provider)->toBe('click')
        ->and($order->currency)->toBe('UZS')
        ->and($order->amount)->toBe(2_900_050)
        ->and($link)->toBeInstanceOf(CheckoutLink::class)
        ->and($link?->kind)->toBe(CheckoutLink::KIND_REDIRECT)
        ->and($link?->expiresAt->toIso8601String())->toBe(CarbonImmutable::instance($order->expires_at)->toIso8601String())
        ->and($link?->url)->toStartWith('https://my.click.uz/services/pay?');

    parse_str((string) parse_url((string) $link?->url, PHP_URL_QUERY), $query);
    expect($query)->toBe([
        'service_id' => '12345',
        'merchant_id' => '6789',
        'amount' => '29000.50',
        'transaction_param' => $order->token,
        'return_url' => 'https://lexiflow.example.com/billing/return?order='.$order->token.'&lang=uz',
    ]);
});

it('adds merchant_user_id only when configured, uses the default language for an organization and never trusts the request host', function (): void {
    config()->set('billing.providers.click.merchant_user_id', '4242');
    config()->set('billing.providers.click.checkout_url', 'https://my.click.uz/services/pay?');
    $provider = app(ClickProvider::class);
    $order = PaymentOrder::factory()->forOrganization(Organization::factory()->create())->create(['provider' => 'click']);

    $link = $provider->createCheckout($order);
    parse_str((string) parse_url($link->url, PHP_URL_QUERY), $query);

    expect($link->url)->toStartWith('https://my.click.uz/services/pay?service_id=')
        ->and($query['merchant_user_id'] ?? null)->toBe('4242')
        ->and($query['amount'] ?? null)->toBe('129000.00')
        ->and($query['return_url'] ?? null)->toBe('https://lexiflow.example.com/billing/return?order='.$order->token.'&lang=ru')
        ->and(BillingReturnUrl::for('tok', 'uz'))->toBe('https://lexiflow.example.com/billing/return?order=tok&lang=uz');

    // Not a Click order / wrong currency.
    expect(fn () => $provider->createCheckout(PaymentOrder::factory()->payme()->create()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $provider->createCheckout(PaymentOrder::factory()->create()))->toThrow(InvalidArgumentException::class);
});

it('refuses to sell without a service id or merchant id and leaves the order failed', function (string $key): void {
    config()->set("billing.providers.click.{$key}", '');
    $learner = Learner::factory()->create();

    expect(fn () => app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Click, null, 'twa'))
        ->toThrow(ProviderCapabilityException::class);
    expect(PaymentOrder::query()->sole()->status)->toBe('failed');
})->with(['service_id', 'merchant_id']);

it('does not renew or refund by itself: those are ProviderCapabilityException (refunds are recorded by hand in the panel)', function (): void {
    $provider = app(ClickProvider::class);
    $subscription = Subscription::factory()->create(['provider' => 'click']);
    $payment = Payment::factory()->create(['provider' => 'click', 'provider_charge_id' => '1234567890']);

    expect(fn () => $provider->cancelAutoRenew($subscription))->toThrow(ProviderCapabilityException::class)
        ->and(fn () => $provider->resumeAutoRenew($subscription))->toThrow(ProviderCapabilityException::class)
        ->and(fn () => $provider->refund($payment))->toThrow(ProviderCapabilityException::class);
});
