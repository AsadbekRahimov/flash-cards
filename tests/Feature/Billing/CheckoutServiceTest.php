<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\CheckoutLink;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function checkoutFailure(callable $call): CheckoutException
{
    try {
        $call();
    } catch (CheckoutException $e) {
        return $e;
    }

    throw new RuntimeException('Expected CheckoutException');
}

it('creates a pending order priced on the server and stores the provider link', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);
    $now = CarbonImmutable::now();

    $order = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    expect($order->status)->toBe('pending')
        ->and(strlen($order->token))->toBe(32)
        ->and($order->subscriber_type)->toBe('learner')
        ->and($order->subscriber_id)->toBe($learner->id)
        ->and($order->plan)->toBe('pro')
        ->and($order->tier)->toBeNull()
        ->and($order->period)->toBe('month')
        ->and($order->provider)->toBe('telegram_stars')
        ->and($order->currency)->toBe('XTR')
        ->and($order->amount)->toBe(150)
        ->and($order->list_amount)->toBe(150)
        ->and($order->offer_id)->toBeNull()
        ->and($order->created_via)->toBe('twa')
        ->and($order->expires_at->toIso8601String())->toBe($now->addMinutes(1440)->toIso8601String())
        ->and($order->provider_ref)->toBe("https://pay.test/{$order->token}")
        ->and($order->meta['learner_locale'])->toBe('uz')
        ->and($order->meta['checkout']['kind'])->toBe(CheckoutLink::KIND_INVOICE_LINK)
        ->and($order->checkoutLink()?->url)->toBe("https://pay.test/{$order->token}")
        ->and($order->checkoutLink()?->kind)->toBe('invoice_link')
        ->and($this->stars->checkouts)->toBe([$order->id]);

    $event = ProductEvent::query()->where('name', 'checkout.started')->sole();
    expect($event->learner_id)->toBe($learner->id)->and($event->props['amount'])->toBe(150)->and($event->props['provider'])->toBe('telegram_stars');

    $year = app(CheckoutService::class)->create($learner, Plan::Pro, 'year', SubscriptionProvider::Payme, null, 'bot');
    expect($year->amount)->toBe(24_900_000)->and($year->currency)->toBe('UZS')->and($year->meta['checkout']['kind'])->toBe(CheckoutLink::KIND_REDIRECT)
        ->and(app(CheckoutService::class)->findByToken($year->token)?->id)->toBe($year->id)
        ->and(app(CheckoutService::class)->findByToken(''))->toBeNull()
        ->and(app(CheckoutService::class)->findByToken(str_repeat('x', 33)))->toBeNull();
});

it('rejects what cannot be sold: bad period, disabled or missing provider, unsupported subscriber, missing or capped price', function (): void {
    $learner = Learner::factory()->create();
    $org = Organization::factory()->create();
    $service = app(CheckoutService::class);

    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'week', SubscriptionProvider::TelegramStars, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::INVALID_PERIOD)->and($e->httpStatus())->toBe(422);

    $e = checkoutFailure(fn () => $service->create($learner, Plan::Free, 'month', SubscriptionProvider::TelegramStars, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PRICE_NOT_CONFIGURED)->and($e->httpStatus())->toBe(409);

    // Click: no adapter registered at all.
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Click, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PROVIDER_DISABLED)->and($e->httpStatus())->toBe(409);

    // Payme: registered but switched off.
    config()->set('billing.providers.payme.enabled', false);
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PROVIDER_DISABLED);
    config()->set('billing.providers.payme.enabled', true);

    // Grants are not providers.
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Manual, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PROVIDER_DISABLED);

    // Stars for an organization (docs/13 §12 #13).
    $e = checkoutFailure(fn () => $service->create($org, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, 'tutor', 'org_panel'));
    expect($e->errorCode)->toBe(CheckoutException::PROVIDER_NOT_SUPPORTED)->and($e->httpStatus())->toBe(422);

    // No price in that currency.
    config()->set('billing.prices.pro.year.XTR', 0);
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PRICE_NOT_CONFIGURED);

    // A Stars *subscription* above Telegram's cap is refused; a one-off year is not capped.
    config()->set('billing.providers.telegram_stars.max_subscription_price', 100);
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::PRICE_NOT_CONFIGURED)->and($e->getMessage())->toContain('cap');
    config()->set('billing.prices.pro.year.XTR', 1300);
    expect($service->create($learner, Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'twa')->amount)->toBe(1300);

    // Organization tiers.
    $e = checkoutFailure(fn () => $service->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'org_panel'));
    expect($e->errorCode)->toBe(CheckoutException::INVALID_TIER)->and($e->httpStatus())->toBe(422);
    $e = checkoutFailure(fn () => $service->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'platinum', 'org_panel'));
    expect($e->errorCode)->toBe(CheckoutException::INVALID_TIER);
    $e = checkoutFailure(fn () => $service->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'center_l', 'org_panel'));
    expect($e->errorCode)->toBe(CheckoutException::PRICE_NOT_CONFIGURED); // manual activation only
    $e = checkoutFailure(fn () => $service->create($org, Plan::Pro, 'year', SubscriptionProvider::Payme, 'tutor', 'org_panel'));
    expect($e->errorCode)->toBe(CheckoutException::PRICE_NOT_CONFIGURED); // tiers are monthly

    $tutor = $service->create($org, Plan::Pro, 'month', SubscriptionProvider::Payme, 'tutor', 'org_panel');
    expect($tutor->amount)->toBe(12_900_000)->and($tutor->tier)->toBe('tutor')->and($tutor->meta)->toBe([
        'checkout' => ['kind' => 'redirect', 'expires_at' => $tutor->expires_at->toIso8601String()],
    ]);
});

it('limits pending orders per subscriber (429) and does not count expired ones', function (): void {
    $learner = Learner::factory()->create();
    $service = app(CheckoutService::class);

    foreach (range(1, 3) as $_) {
        $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    }

    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::TOO_MANY_PENDING)->and($e->httpStatus())->toBe(429)
        ->and(PaymentOrder::query()->count())->toBe(3);

    // Time passes, the scheduler expires them: room again.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
    expect($service->expirePending())->toBe(3)
        ->and(PaymentOrder::query()->where('status', 'expired')->count())->toBe(3)
        ->and($service->expirePending())->toBe(0);
    expect($service->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa')->status)->toBe('pending');
});

it('does not expire an order whose Payme transaction is still open and within the provider timeout, and expires it once that transaction is stale', function (): void {
    $service = app(CheckoutService::class);
    $live = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subMinute()]);
    ProviderTransaction::factory()->create(['payment_order_id' => $live->id, 'provider_time' => CarbonImmutable::now()->subHours(2)->getTimestampMs(), 'created_at_provider' => CarbonImmutable::now()->subHours(2)]);
    $stale = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subMinute()]);
    ProviderTransaction::factory()->create(['payment_order_id' => $stale->id, 'provider_time' => CarbonImmutable::now()->subHours(13)->getTimestampMs(), 'created_at_provider' => CarbonImmutable::now()->subHours(13)]);
    $closed = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subMinute()]);
    ProviderTransaction::factory()->canceled()->create(['payment_order_id' => $closed->id, 'created_at_provider' => CarbonImmutable::now()->subMinutes(5)]);
    $plain = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subMinute()]);

    expect($service->expirePending())->toBe(3)
        ->and($live->refresh()->status)->toBe('pending')
        ->and($stale->refresh()->status)->toBe('expired')
        ->and($closed->refresh()->status)->toBe('expired')
        ->and($plain->refresh()->status)->toBe('expired');

    // Twelve hours later the open transaction is stale too (Payme would cancel it with reason 4): the order expires.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(11));
    expect($service->expirePending())->toBe(1)->and($live->refresh()->status)->toBe('expired');
});

it('refuses any order while a Stars subscription still renews, allows it once canceled, and allows Stars on top of a paid period only near its end', function (): void {
    $learner = Learner::factory()->create();
    $service = app(CheckoutService::class);
    $stars = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'telegram_stars', 'current_period_ends_at' => CarbonImmutable::now()->addDays(20)]);

    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::CONFLICT)->and($e->httpStatus())->toBe(409)
        ->and(PaymentOrder::query()->count())->toBe(0);

    // Stars again is refused as well: a second order would open a second Telegram subscription ("Реализация 2A.8").
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'twa'));
    expect($e->errorCode)->toBe(CheckoutException::CONFLICT);

    // Canceled auto-renewal: Payme may extend.
    $stars->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()]);
    expect($service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa')->status)->toBe('pending');

    // Payme period live: a Stars subscription on top is allowed in the last days (the end becomes max, docs/13 §4.4) ...
    $stars->update(['status' => 'active', 'canceled_at' => null, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(5)]);
    expect($service->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa')->status)->toBe('pending');

    // ... and refused while the prepaid period is longer than the window (subscribe_too_early).
    $stars->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(20)]);
    expect(checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa'))->errorCode)->toBe(CheckoutException::SUBSCRIBE_TOO_EARLY);
});

it('applies the best open offer automatically, validates an explicit one, and never applies a used, expired or foreign offer', function (): void {
    $learner = Learner::factory()->create();
    $service = app(CheckoutService::class);
    Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'campaign', 'discount_pct' => 10, 'period' => null]);
    $best = Offer::factory()->create(['learner_id' => $learner->id, 'kind' => 'post_org_discount', 'discount_pct' => 30, 'period' => 'month']);
    Offer::factory()->expired()->create(['learner_id' => $learner->id, 'kind' => 'winback', 'discount_pct' => 90]);

    $order = $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($order->offer_id)->toBe($best->id)->and($order->amount)->toBe(2_030_000)->and($order->list_amount)->toBe(2_900_000);

    // The 30 % offer is for a month: a year gets the 10 % campaign one.
    $year = $service->create($learner, Plan::Pro, 'year', SubscriptionProvider::TelegramStars, null, 'twa');
    expect($year->amount)->toBe(1170)->and($year->offer_id)->not->toBe($best->id);

    // Explicit offer of someone else / expired / for another period -> offer_not_applicable.
    $foreign = Offer::factory()->create();
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa', $foreign));
    expect($e->errorCode)->toBe(CheckoutException::OFFER_NOT_APPLICABLE)->and($e->httpStatus())->toBe(409);
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'year', SubscriptionProvider::Payme, null, 'twa', $best));
    expect($e->errorCode)->toBe(CheckoutException::OFFER_NOT_APPLICABLE);

    // Once paid, the offer is redeemed and no longer applied.
    app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($order));
    $next = $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    expect($next->offer_id)->not->toBe($best->id)->and($next->amount)->toBe(2_610_000); // the 10 % campaign offer is still open
    $e = checkoutFailure(fn () => $service->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa', $best->refresh()));
    expect($e->errorCode)->toBe(CheckoutException::OFFER_NOT_APPLICABLE);
});

it('marks the order failed and rethrows when the provider cannot create the checkout', function (): void {
    $learner = Learner::factory()->create();
    $this->stars->failCheckoutWith = new RuntimeException('telegram down');

    expect(fn () => app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa'))
        ->toThrow(RuntimeException::class, 'telegram down');

    $order = PaymentOrder::query()->sole();
    expect($order->status)->toBe('failed')->and($order->provider_ref)->toBeNull()->and($order->meta['checkout_error'])->toBe(RuntimeException::class);

    // A failed order does not count as pending.
    $this->stars->failCheckoutWith = null;
    foreach (range(1, 3) as $_) {
        app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    }
    expect(PaymentOrder::query()->where('status', 'pending')->count())->toBe(3);
});

it('describes the catalog: priced items per enabled provider, the open offer and the live subscription', function (): void {
    $learner = Learner::factory()->create();
    $org = Organization::factory()->create();
    $service = app(CheckoutService::class);
    $now = CarbonImmutable::now();

    $catalog = $service->catalog($learner, $now);
    expect($catalog->isEmpty())->toBeFalse()
        ->and($catalog->providers)->toBe(['telegram_stars', 'payme'])
        ->and($catalog->items)->toBe([
            ['plan' => 'pro', 'tier' => null, 'period' => 'month', 'prices' => ['XTR' => 150, 'UZS' => 2_900_000], 'list_prices' => ['XTR' => 150, 'UZS' => 2_900_000]],
            ['plan' => 'pro', 'tier' => null, 'period' => 'year', 'prices' => ['XTR' => 1300, 'UZS' => 24_900_000], 'list_prices' => ['XTR' => 1300, 'UZS' => 24_900_000]],
        ])
        ->and($catalog->offer)->toBeNull()
        ->and($catalog->subscription)->toBeNull();

    // An open month offer discounts the month row only.
    $offer = Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $catalog = $service->catalog($learner, $now);
    expect($catalog->items[0]['prices'])->toBe(['XTR' => 105, 'UZS' => 2_030_000])
        ->and($catalog->items[0]['list_prices'])->toBe(['XTR' => 150, 'UZS' => 2_900_000])
        ->and($catalog->items[1]['prices'])->toBe(['XTR' => 1300, 'UZS' => 24_900_000])
        ->and($catalog->offer)->toBe(['kind' => 'post_org_discount', 'discount_pct' => 30, 'period' => 'month', 'expires_at' => CarbonImmutable::instance($offer->expires_at)->toIso8601String()]);

    // A live Stars subscription: auto-renewing, can be canceled; once canceled it can be resumed; ends_at is the liveness end.
    $sub = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'telegram_stars', 'current_period_ends_at' => $now->addDays(20)]);
    expect($service->catalog($learner, $now)->subscription)->toBe([
        'status' => 'active', 'provider' => 'telegram_stars', 'plan' => 'pro', 'ends_at' => $now->addDays(23)->toIso8601String(), 'auto_renew' => true, 'can_cancel' => true, 'can_resume' => false, 'perpetual' => false,
    ]);
    $sub->update(['status' => 'canceled', 'canceled_at' => $now]);
    expect($service->catalog($learner, $now)->subscription)->toMatchArray(['status' => 'canceled', 'ends_at' => $now->addDays(20)->toIso8601String(), 'auto_renew' => false, 'can_cancel' => false, 'can_resume' => true]);
    $sub->update(['status' => 'active', 'canceled_at' => null, 'provider' => 'payme']);
    expect($service->catalog($learner, $now)->subscription)->toMatchArray(['provider' => 'payme', 'ends_at' => $now->addDays(20)->toIso8601String(), 'auto_renew' => false, 'can_cancel' => false, 'can_resume' => false]);

    // Organizations: tiers x periods in UZS only (Stars does not serve them); center_l has no price.
    $catalog = $service->catalog($org, $now);
    expect($catalog->providers)->toBe(['payme'])
        ->and(array_column($catalog->items, 'tier'))->toBe(['tutor', 'center_s', 'center_m'])
        ->and($catalog->items[0])->toBe(['plan' => 'pro', 'tier' => 'tutor', 'period' => 'month', 'prices' => ['UZS' => 12_900_000], 'list_prices' => ['UZS' => 12_900_000]]);

    // Nothing enabled -> empty catalog (paywall falls back to coming_soon).
    config()->set('billing.providers.telegram_stars.enabled', false);
    config()->set('billing.providers.payme.enabled', false);
    expect($service->catalog($learner, $now)->isEmpty())->toBeTrue()
        ->and($service->catalog($learner, $now)->providers)->toBe([])
        ->and($service->catalog($learner, $now)->toArray()['items'])->toBe([]);
});

it('keeps the provider registry honest: unknown providers are not registered, enabled() follows config', function (): void {
    $registry = app(BillingProviderRegistry::class);

    // Click is registered by AppServiceProvider (2A.5) but sellable only when CLICK_ENABLED=true; grants are never providers.
    expect($registry->has(SubscriptionProvider::Click))->toBeTrue()
        ->and($registry->isEnabled(SubscriptionProvider::Click))->toBeFalse()
        ->and($registry->has(SubscriptionProvider::Reward))->toBeFalse()
        ->and($registry->enabled())->toBe([SubscriptionProvider::TelegramStars, SubscriptionProvider::Payme])
        ->and($registry->for(SubscriptionProvider::Payme))->toBe($this->payme);

    config()->set('billing.providers.telegram_stars.enabled', false);
    expect($registry->enabled())->toBe([SubscriptionProvider::Payme])
        ->and($registry->has(SubscriptionProvider::TelegramStars))->toBeTrue();

    try {
        $registry->for(SubscriptionProvider::Reward);
        $this->fail('expected ProviderCapabilityException');
    } catch (ProviderCapabilityException $e) {
        expect($e->errorCode)->toBe(ProviderCapabilityException::NOT_REGISTERED)->and($e->httpStatus())->toBe(409);
    }

    // The fake mirrors the real capability matrix: only Stars renews / refunds.
    expect(fn () => $this->payme->cancelAutoRenew(Subscription::factory()->make()))->toThrow(ProviderCapabilityException::class);
    expect(fn () => $this->payme->refund(Payment::factory()->make()))->toThrow(ProviderCapabilityException::class);
    $this->stars->cancelAutoRenew(Subscription::factory()->make(['id' => 7]));
    expect($this->stars->canceled)->toBe([7]);
});
