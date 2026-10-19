<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Exceptions\ProviderUnavailableException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * TelegramStarsProvider (docs/13 §2.2, §4.1): invoice links, the parsers of `successful_payment` /
 * `refunded_payment` (the one place that knows the Bot API field names — docs/13 §15), cancel /
 * resume / refund through the Bot API. The real adapter is registered by AppServiceProvider and
 * talks to FakeTelegramClient here.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> A Telegram `Message` with `successful_payment` (Bot API 7.x / 8.x field names). */
function starsPaidMessage(PaymentOrder $order, string $chargeId, int $fromId, bool $subscription = true, bool $first = true, ?int $expiration = null, ?int $amount = null, ?string $currency = null): array
{
    $payment = [
        'currency' => $currency ?? 'XTR',
        'total_amount' => $amount ?? $order->amount,
        'invoice_payload' => $order->token,
        'telegram_payment_charge_id' => $chargeId,
        'provider_payment_charge_id' => 'prov_'.$chargeId,
    ];

    if ($subscription) {
        $payment['subscription_expiration_date'] = $expiration ?? CarbonImmutable::now()->addDays(30)->getTimestamp();
        $payment['is_recurring'] = true;
        if ($first) {
            $payment['is_first_recurring'] = true;
        }
    }

    return [
        'message_id' => 900,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => 'ru'],
        'successful_payment' => $payment,
    ];
}

it('is registered as the real Stars adapter and only serves learners paying in XTR', function (): void {
    $registry = app(BillingProviderRegistry::class);
    $provider = $registry->for(SubscriptionProvider::TelegramStars);

    expect($provider)->toBeInstanceOf(TelegramStarsProvider::class)
        ->and($provider->supports('learner', 'XTR'))->toBeTrue()
        ->and($provider->supports('organization', 'XTR'))->toBeFalse()
        ->and($provider->supports('learner', 'UZS'))->toBeFalse()
        ->and($registry->isEnabled(SubscriptionProvider::TelegramStars))->toBeTrue();

    config()->set('billing.providers.telegram_stars.enabled', false);
    expect($registry->isEnabled(SubscriptionProvider::TelegramStars))->toBeFalse()
        ->and($registry->enabled())->toBe([]);
});

it('creates a monthly Stars subscription invoice: payload = token, XTR, one price, subscription_period 30 days, texts on the learner locale', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    expect($this->telegram->invoiceLinks)->toHaveCount(1);
    $invoice = $this->telegram->invoiceLinks[0];
    expect($invoice['payload'])->toBe($order->token)
        ->and($invoice['currency'])->toBe('XTR')
        ->and($invoice['prices'])->toBe([['label' => 'LexiFlow Pro, 1 oy', 'amount' => $order->amount]])
        ->and($invoice['subscription_period'])->toBe(2_592_000)
        ->and($invoice['title'])->toBe('LexiFlow Pro — 1 oy')
        ->and(mb_strlen($invoice['title']))->toBeLessThanOrEqual(32)
        ->and(mb_strlen($invoice['description']))->toBeLessThanOrEqual(255)
        ->and($invoice['description'])->toContain('Har 30 kunda avtomatik yangilanadi');

    expect($order->refresh()->provider_ref)->toBe('https://t.me/$fake-invoice-1')
        ->and($order->checkoutLink()?->kind)->toBe('invoice_link')
        ->and($order->checkoutLink()?->expiresAt->toIso8601String())->toBe(CarbonImmutable::instance($order->expires_at)->toIso8601String())
        ->and($order->status)->toBe('pending');
});

it('creates a yearly Stars invoice as a one-off payment without subscription_period', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'bot');

    $invoice = $this->telegram->invoiceLinks[0];
    expect($invoice['subscription_period'])->toBeNull()
        ->and($invoice['title'])->toBe('LexiFlow Pro — 1 год')
        ->and($invoice['description'])->toContain('Разовая оплата без автопродления')
        ->and($invoice['prices'][0]['amount'])->toBe($order->amount)
        ->and($order->amount)->toBe((int) config('billing.prices.pro.year.XTR'));
});

it('refuses a monthly price above the Stars subscription cap before calling Telegram', function (): void {
    config()->set('billing.prices.pro.month.XTR', 10_001);

    expect(fn () => app(CheckoutService::class)->create(Learner::factory()->create(), Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa'))
        ->toThrow(CheckoutException::class, 'cap');
    expect($this->telegram->invoiceLinks)->toBe([])
        ->and(PaymentOrder::query()->count())->toBe(0);
});

it('fails the order and reports provider_unavailable when Telegram cannot create the invoice link', function (): void {
    $this->telegram->failStars = true;
    $learner = Learner::factory()->create();

    try {
        app(LearnerBillingService::class)->checkout($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, 'twa');
        $this->fail('expected ProviderUnavailableException');
    } catch (ProviderUnavailableException $e) {
        expect($e->errorCode)->toBe('provider_unavailable')->and($e->httpStatus())->toBe(502);
    }

    $order = PaymentOrder::query()->sole();
    expect($order->status)->toBe('failed')->and($order->provider_ref)->toBeNull();
});

it('refuses to build an invoice for an order of another provider or currency', function (): void {
    $provider = app(TelegramStarsProvider::class);
    $order = PaymentOrder::factory()->create(['provider' => 'payme', 'currency' => 'UZS']);

    expect(fn () => $provider->createCheckout($order))->toThrow(InvalidArgumentException::class);
    expect($this->telegram->invoiceLinks)->toBe([]);
});

// --- parsers (fixtures: docs/13 §15 — field names to verify on the staging bot) ---------------

it('parses the first payment of a subscription: charge id as event id and charge id, token, amount, period end, not a renewal', function (): void {
    $learner = Learner::factory()->create();
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $expiration = CarbonImmutable::now()->addDays(30)->getTimestamp();

    $event = app(TelegramStarsProvider::class)->parseSuccessfulPayment(starsPaidMessage($order, 'ch_first', $learner->telegram_user_id, expiration: $expiration));

    expect($event->provider)->toBe(SubscriptionProvider::TelegramStars)
        ->and($event->providerEventId)->toBe('ch_first')
        ->and($event->providerChargeId)->toBe('ch_first')
        ->and($event->orderToken)->toBe($order->token)
        ->and($event->amount)->toBe($order->amount)
        ->and($event->currency)->toBe('XTR')
        ->and($event->isRenewal)->toBeFalse()
        ->and($event->periodEndsAt?->getTimestamp())->toBe($expiration)
        ->and($event->occurredAt->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($event->providerTransactionId)->toBeNull()
        ->and($event->raw['is_recurring'])->toBeTrue()
        ->and($event->raw['is_first_recurring'])->toBeTrue()
        ->and($event->raw['provider_payment_charge_id'])->toBe('prov_ch_first')
        ->and($event->raw['from_id'])->toBe($learner->telegram_user_id)
        ->and($event->raw)->not->toHaveKey('invoice_payload');
});

it('parses a recurring charge as a renewal and a one-off year invoice as a plain charge without a period end', function (): void {
    $learner = Learner::factory()->create();
    $provider = app(TelegramStarsProvider::class);
    $month = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    $renewal = $provider->parseSuccessfulPayment(starsPaidMessage($month, 'ch_second', $learner->telegram_user_id, first: false, expiration: CarbonImmutable::now()->addDays(60)->getTimestamp()));
    expect($renewal->isRenewal)->toBeTrue()
        ->and($renewal->periodEndsAt?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(60)->toIso8601String())
        ->and($renewal->raw['is_first_recurring'])->toBeFalse();

    $year = app(CheckoutService::class)->create(Learner::factory()->create(), Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'twa');
    $oneOff = $provider->parseSuccessfulPayment(starsPaidMessage($year, 'ch_year', 1, subscription: false));
    expect($oneOff->isRenewal)->toBeFalse()
        ->and($oneOff->periodEndsAt)->toBeNull()
        ->and($oneOff->raw['is_recurring'])->toBeFalse()
        ->and($oneOff->raw['subscription_expiration_date'])->toBeNull();
});

it('rejects a successful_payment without the fields it needs', function (): void {
    $provider = app(TelegramStarsProvider::class);

    expect(fn () => $provider->parseSuccessfulPayment(['text' => 'hi']))->toThrow(InvalidArgumentException::class, 'successful_payment');
    expect(fn () => $provider->parseSuccessfulPayment(['successful_payment' => ['currency' => 'XTR', 'total_amount' => 1, 'invoice_payload' => 'tok']]))
        ->toThrow(InvalidArgumentException::class, 'telegram_payment_charge_id');
    expect(fn () => $provider->parseSuccessfulPayment(['successful_payment' => ['currency' => 'XTR', 'total_amount' => 1, 'telegram_payment_charge_id' => 'c']]))
        ->toThrow(InvalidArgumentException::class, 'invoice_payload');
});

it('parses a refunded_payment with a prefixed event id so it never collides with the charge row', function (): void {
    $event = app(TelegramStarsProvider::class)->parseRefundedPayment([
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => 7, 'type' => 'private'],
        'from' => ['id' => 7],
        'refunded_payment' => ['currency' => 'XTR', 'total_amount' => 150, 'invoice_payload' => 'tok', 'telegram_payment_charge_id' => 'ch_first', 'provider_payment_charge_id' => 'p1'],
    ]);

    expect($event->providerEventId)->toBe('refund:ch_first')
        ->and($event->refundedProviderChargeId)->toBe('ch_first')
        ->and($event->amount)->toBe(150)
        ->and($event->currency)->toBe('XTR')
        ->and($event->raw['source'])->toBe('telegram.refunded_payment')
        ->and($event->raw)->not->toHaveKey('invoice_payload');

    expect(fn () => app(TelegramStarsProvider::class)->parseRefundedPayment(['refunded_payment' => ['currency' => 'XTR']]))
        ->toThrow(InvalidArgumentException::class, 'telegram_payment_charge_id');
});

// --- cancel / resume / refund ------------------------------------------------------------------

it('cancels and resumes the auto-renewal through editUserStarSubscription with the first charge id of the subscription', function (): void {
    $learner = Learner::factory()->create();
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    app(PaymentApplier::class)->applyCharge(app(TelegramStarsProvider::class)->parseSuccessfulPayment(starsPaidMessage($order, 'ch_first', $learner->telegram_user_id)));
    app(PaymentApplier::class)->applyCharge(app(TelegramStarsProvider::class)->parseSuccessfulPayment(starsPaidMessage($order, 'ch_second', $learner->telegram_user_id, first: false, expiration: CarbonImmutable::now()->addDays(60)->getTimestamp())));
    $subscription = Subscription::query()->sole();
    expect($subscription->provider_ref)->toBe('ch_second')->and($subscription->meta['stars']['charge_id'])->toBe('ch_first');

    $provider = app(TelegramStarsProvider::class);
    $provider->cancelAutoRenew($subscription);
    $provider->resumeAutoRenew($subscription);

    expect($this->telegram->starSubscriptionEdits)->toBe([
        ['user_id' => $learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => true],
        ['user_id' => $learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => false],
    ]);
});

it('refuses cancel / resume / refund without a Stars charge id or for another provider', function (): void {
    $provider = app(TelegramStarsProvider::class);
    $learner = Learner::factory()->create();

    $payme = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'payme', 'provider_ref' => 'x']);
    expect(fn () => $provider->cancelAutoRenew($payme))->toThrow(ProviderCapabilityException::class);

    $noCharge = Subscription::factory()->create(['subscriber_id' => Learner::factory()->create()->id, 'provider' => 'telegram_stars', 'provider_ref' => null, 'meta' => []]);
    expect(fn () => $provider->resumeAutoRenew($noCharge))->toThrow(ProviderCapabilityException::class);

    $payment = Payment::factory()->create(['provider' => 'payme', 'subscriber_type' => 'learner', 'subscriber_id' => $learner->id, 'provider_charge_id' => 'p1']);
    expect(fn () => $provider->refund($payment))->toThrow(ProviderCapabilityException::class);

    $noId = Payment::factory()->create(['provider' => 'telegram_stars', 'subscriber_type' => 'learner', 'subscriber_id' => $learner->id, 'provider_charge_id' => null]);
    expect(fn () => $provider->refund($noId))->toThrow(ProviderCapabilityException::class);

    expect($this->telegram->starSubscriptionEdits)->toBe([])->and($this->telegram->starRefunds)->toBe([]);
});

it('refunds one charge through refundStarPayment with the learner Telegram id', function (): void {
    $learner = Learner::factory()->create();
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $charge = app(PaymentApplier::class)->applyCharge(app(TelegramStarsProvider::class)->parseSuccessfulPayment(starsPaidMessage($order, 'ch_first', $learner->telegram_user_id)));

    app(TelegramStarsProvider::class)->refund($charge);

    expect($this->telegram->starRefunds)->toBe([['user_id' => $learner->telegram_user_id, 'charge_id' => 'ch_first']]);
});
