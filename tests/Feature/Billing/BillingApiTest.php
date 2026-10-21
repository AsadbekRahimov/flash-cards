<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeBillingProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->stars = FakeBillingProvider::register(SubscriptionProvider::TelegramStars);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    config()->set('billing.paywall.mode', 'purchase');
});

/** @return array<string, string> */
function billingHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** A pending order of the learner paid through the fake provider, as the webhook would. */
function paidOrder(Learner $learner, FakeBillingProvider $fake, string $period = 'month'): PaymentOrder
{
    $order = app(CheckoutService::class)->create($learner, Plan::Pro, $period, $fake->key(), null, CheckoutService::VIA_TWA);
    app(PaymentApplier::class)->applyCharge($fake->emitCharge($order));

    return $order->refresh();
}

// --- catalog ----------------------------------------------------------------

it('lists what can be bought with the enabled providers and no subscription', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertExactJsonStructure([
            'items' => ['*' => ['plan', 'tier', 'period', 'prices' => ['XTR', 'UZS'], 'list_prices' => ['XTR', 'UZS']]],
            'providers',
            'offer',
            'subscription',
        ])
        ->assertJsonPath('items.0.plan', 'pro')
        ->assertJsonPath('items.0.period', 'month')
        ->assertJsonPath('items.0.prices.XTR', 150)
        ->assertJsonPath('items.0.prices.UZS', 2_900_000)
        ->assertJsonPath('items.1.period', 'year')
        ->assertJsonPath('providers', ['telegram_stars', 'payme'])
        ->assertJsonPath('offer', null)
        ->assertJsonPath('subscription', null);
});

it('has nothing to sell when no provider is enabled', function (): void {
    FakeBillingProvider::register(SubscriptionProvider::TelegramStars, enabled: false);
    FakeBillingProvider::register(SubscriptionProvider::Payme, enabled: false);

    $this->withHeaders(billingHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertJsonPath('items', [])
        ->assertJsonPath('providers', []);
});

it('shows the open offer discount and the live subscription in the catalog', function (): void {
    $learner = Learner::factory()->create();
    paidOrder($learner, $this->stars);
    Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => null]);

    $response = $this->withHeaders(billingHeaders($learner))->getJson('/api/twa/billing/catalog')->assertOk();

    expect($response->json('offer.discount_pct'))->toBe(30)
        ->and($response->json('items.0.prices.XTR'))->toBe(105)
        ->and($response->json('items.0.list_prices.XTR'))->toBe(150)
        ->and($response->json('subscription.provider'))->toBe('telegram_stars')
        ->and($response->json('subscription.auto_renew'))->toBeTrue()
        ->and($response->json('subscription.can_cancel'))->toBeTrue()
        ->and($response->json('subscription.can_resume'))->toBeFalse();
});

// --- checkout -> payment -> /me ----------------------------------------------

it('checkout creates a pending order, the charge makes /me show Pro with its renewal, the order endpoint reports paid', function (): void {
    $learner = Learner::factory()->create();

    $created = $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'])
        ->assertCreated()
        ->assertExactJsonStructure([
            'order' => ['id', 'token', 'status', 'amount', 'currency', 'expires_at'],
            'checkout' => ['url', 'kind'],
        ])
        ->assertJsonPath('order.status', 'pending')
        ->assertJsonPath('order.amount', 150)
        ->assertJsonPath('order.currency', 'XTR')
        ->assertJsonPath('checkout.kind', 'invoice_link');

    $token = (string) $created->json('order.token');
    $order = PaymentOrder::query()->where('token', $token)->sole();
    expect($order->subscriber_id)->toBe($learner->id)
        ->and($order->status)->toBe('pending')
        ->and($order->created_via)->toBe('twa')
        ->and($created->json('checkout.url'))->toBe("https://pay.test/{$token}");

    $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$token}")
        ->assertOk()
        ->assertExactJsonStructure(['status', 'paid_at', 'reason', 'replaced_by', 'subscription'])
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('paid_at', null)
        ->assertJsonPath('subscription', null);

    $this->withHeaders(billingHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('entitlement.plan', 'free');

    app(PaymentApplier::class)->applyCharge($this->stars->emitCharge($order, periodEndsAt: CarbonImmutable::now()->addDays(30)));

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/billing/orders/'.$token)
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('subscription.status', 'active')
        ->assertJsonPath('subscription.provider', 'telegram_stars')
        ->assertJsonPath('subscription.auto_renew', true);

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.source', 'subscription')
        ->assertJsonPath('entitlement.renewal.auto', true)
        ->assertJsonPath('entitlement.renewal.provider', 'telegram_stars')
        ->assertJsonPath('entitlement.renewal.reminder_sent', false)
        ->assertJsonStructure(['entitlement' => ['renewal' => ['auto', 'provider', 'ends_at', 'reminder_sent']]]);
});

it('a Payme period is not auto-renewing and gets the redirect kind', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'year', 'provider' => 'payme'])
        ->assertCreated()
        ->assertJsonPath('order.amount', 24_900_000)
        ->assertJsonPath('order.currency', 'UZS')
        ->assertJsonPath('checkout.kind', 'redirect');

    paidOrder($learner, $this->payme);

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/me')
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.renewal.auto', false)
        ->assertJsonPath('entitlement.renewal.provider', 'payme');
});

it('reports reminder_sent once a renewal reminder was raised for the current period end', function (): void {
    $learner = Learner::factory()->create();
    $order = paidOrder($learner, $this->payme);
    $sub = Subscription::query()->findOrFail($order->subscription_id);
    $ends = CarbonImmutable::instance($sub->current_period_ends_at);
    $sub->update(['meta' => [...$sub->meta, 'renewal_reminders' => ['3@'.$ends->toIso8601String()]]]);

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/me')
        ->assertJsonPath('entitlement.renewal.reminder_sent', true);

    // a reminder of a previous period does not count for the renewed one
    $sub->update(['current_period_ends_at' => $ends->addDays(30)]);
    app(EntitlementResolver::class)->forget($learner);

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/me')
        ->assertJsonPath('entitlement.renewal.reminder_sent', false);
});

it('gives Free learners and sponsored learners no renewal', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/me')
        ->assertJsonPath('entitlement.renewal', null);
});

// --- orders belong to their owner ---------------------------------------------

it('answers 404 for someone else\'s order, an unknown token and a malformed one', function (): void {
    $owner = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $order = app(CheckoutService::class)->create($owner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    $this->withHeaders(billingHeaders($stranger))
        ->getJson("/api/twa/billing/orders/{$order->token}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'order_not_found');

    $this->withHeaders(billingHeaders($owner))->getJson('/api/twa/billing/orders/'.str_repeat('a', 32))->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
    $this->withHeaders(billingHeaders($owner))->getJson('/api/twa/billing/orders/'.str_repeat('a', 80))->assertNotFound()->assertJsonPath('error.code', 'order_not_found');
    $this->withHeaders(billingHeaders($owner))->getJson("/api/twa/billing/orders/{$order->token}")->assertOk();
});

// --- checkout errors -----------------------------------------------------------

it('maps checkout failures to their status and stable code', function (array $body, int $status, string $code, ?Closure $arrange): void {
    $learner = Learner::factory()->create();
    if ($arrange !== null) {
        $arrange($learner, $this);
    }

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', $body)
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code)
        ->assertJsonStructure(['error' => ['code', 'message']]);
})->with([
    'provider switched off' => [
        ['plan' => 'pro', 'period' => 'month', 'provider' => 'payme'], 409, 'provider_disabled',
        fn () => FakeBillingProvider::register(SubscriptionProvider::Payme, enabled: false),
    ],
    'provider not bound at all' => [
        ['plan' => 'pro', 'period' => 'month', 'provider' => 'click'], 409, 'provider_disabled', null,
    ],
    'no price configured' => [
        ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'], 409, 'price_not_configured',
        fn () => config()->set('billing.prices.pro.month.XTR', 0),
    ],
    'free is not for sale' => [
        ['plan' => 'free', 'period' => 'month', 'provider' => 'telegram_stars'], 409, 'price_not_configured', null,
    ],
    'auto-renewing Stars subscription blocks another provider' => [
        ['plan' => 'pro', 'period' => 'month', 'provider' => 'payme'], 409, 'checkout_conflict',
        fn (Learner $l, $t) => paidOrder($l, $t->stars),
    ],
    'too many pending orders' => [
        ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'], 429, 'too_many_pending',
        function (Learner $l): void {
            foreach (range(1, 3) as $_) {
                app(CheckoutService::class)->create($l, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
            }
        },
    ],
]);

/** A learner whose open offer sits on a Payme order that the provider is paying right now. */
function busyOfferLearner(): array
{
    $learner = Learner::factory()->create();
    Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $holder = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    ProviderTransaction::factory()->create(['payment_order_id' => $holder->id, 'amount' => $holder->amount]);

    return [$learner, $holder];
}

it('answers 409 offer_busy while the discounted order is being paid, and changes no order', function (): void {
    [$learner, $holder] = busyOfferLearner();
    $before = PaymentOrder::query()->orderBy('id')->get(['id', 'status', 'meta', 'offer_id'])->toArray();

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'offer_busy');

    expect(PaymentOrder::query()->orderBy('id')->get(['id', 'status', 'meta', 'offer_id'])->toArray())->toBe($before)
        ->and($holder->refresh()->status)->toBe(PaymentOrder::STATUS_PENDING);
});

it('reports an order replaced by a newer one as canceled / replaced with the new token, never an id', function (): void {
    $learner = Learner::factory()->create();
    Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $old = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    $new = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    $response = $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$old->token}")
        ->assertOk()
        ->assertExactJsonStructure(['status', 'paid_at', 'reason', 'replaced_by', 'subscription'])
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('reason', 'replaced')
        ->assertJsonPath('replaced_by', $new->token);

    expect($response->getContent())->not->toContain('offer_moved');

    $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$new->token}")
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', null)
        ->assertJsonPath('replaced_by', null);
});

it('does not reveal the successor when it is not the learner\'s own order, and ignores other cancellations', function (): void {
    $learner = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $foreign = app(CheckoutService::class)->create($stranger, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $old = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    $old->update(['status' => PaymentOrder::STATUS_CANCELED, 'meta' => [...$old->meta, 'canceled_reason' => 'offer_moved', 'offer_moved_to_order_id' => $foreign->id]]);

    $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$old->token}")
        ->assertJsonPath('reason', 'replaced')
        ->assertJsonPath('replaced_by', null);

    $plain = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $plain->update(['status' => PaymentOrder::STATUS_CANCELED]);

    $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$plain->token}")
        ->assertJsonPath('status', 'canceled')
        ->assertJsonPath('reason', null)
        ->assertJsonPath('replaced_by', null);
});

it('points a redeemed-offer cancellation to the order that used the offer up', function (): void {
    $learner = Learner::factory()->create();
    $offer = Offer::factory()->create(['learner_id' => $learner->id, 'discount_pct' => 30, 'period' => 'month']);
    $old = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::Payme, null, 'twa');
    $paid = PaymentOrder::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id, 'status' => PaymentOrder::STATUS_PAID]);
    $offer->update(['payment_order_id' => $paid->id, 'redeemed_at' => now()]);
    $old->update(['status' => PaymentOrder::STATUS_CANCELED, 'meta' => [...$old->meta, 'canceled_reason' => 'offer_redeemed']]);

    $this->withHeaders(billingHeaders($learner))
        ->getJson("/api/twa/billing/orders/{$old->token}")
        ->assertJsonPath('reason', 'replaced')
        ->assertJsonPath('replaced_by', $paid->token);
});

it('validates the checkout body', function (array $body, string $field): void {
    $this->withHeaders(billingHeaders(Learner::factory()->create()))
        ->postJson('/api/twa/billing/checkout', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'no body' => [[], 'plan'],
    'unknown plan' => [['plan' => 'gold', 'period' => 'month', 'provider' => 'payme'], 'plan'],
    'unknown period' => [['plan' => 'pro', 'period' => 'week', 'provider' => 'payme'], 'period'],
    'a grant is not a payment provider' => [['plan' => 'pro', 'period' => 'month', 'provider' => 'manual'], 'provider'],
    'no provider' => [['plan' => 'pro', 'period' => 'month'], 'provider'],
    'array provider' => [['plan' => 'pro', 'period' => 'month', 'provider' => ['payme']], 'provider'],
]);

it('never trusts an amount sent by the client', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars', 'amount' => 1, 'price' => 1])
        ->assertCreated()
        ->assertJsonPath('order.amount', 150);
});

it('answers 502 provider_unavailable and fails the order when the provider cannot make a link', function (): void {
    $this->stars->failCheckoutWith = new RuntimeException('telegram is down');
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'telegram_stars'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'provider_unavailable');

    expect(PaymentOrder::query()->sole()->status)->toBe('failed');
});

it('throttles cancel / resume of auto-renewal together: the 7th request in a minute is 429, per learner', function (): void {
    $learner = Learner::factory()->create();

    foreach (range(1, 3) as $_) {
        $this->withHeaders(billingHeaders($learner))->postJson('/api/twa/billing/subscription/cancel')->assertStatus(409);
        $this->withHeaders(billingHeaders($learner))->postJson('/api/twa/billing/subscription/resume')->assertStatus(409);
    }

    $this->withHeaders(billingHeaders($learner))->postJson('/api/twa/billing/subscription/cancel')->assertStatus(429);
    $this->withHeaders(billingHeaders($learner))->postJson('/api/twa/billing/subscription/resume')->assertStatus(429);
    $this->withHeaders(billingHeaders(Learner::factory()->create()))->postJson('/api/twa/billing/subscription/cancel')->assertStatus(409);
});

it('throttles checkout: the 11th request in a minute is 429', function (): void {
    $learner = Learner::factory()->create();

    // cheap failures (the provider is not bound) still count against the limit
    foreach (range(1, 10) as $_) {
        $this->withHeaders(billingHeaders($learner))
            ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'click'])
            ->assertStatus(409);
    }

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'click'])
        ->assertStatus(429);

    // the limit is per learner
    $this->withHeaders(billingHeaders(Learner::factory()->create()))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'click'])
        ->assertStatus(409);
});

it('requires a token on every billing route', function (string $method, string $path): void {
    $this->json($method, $path)->assertStatus(401);
})->with([
    ['GET', '/api/twa/billing/catalog'],
    ['POST', '/api/twa/billing/checkout'],
    ['GET', '/api/twa/billing/orders/abc'],
    ['POST', '/api/twa/billing/subscription/cancel'],
    ['POST', '/api/twa/billing/subscription/resume'],
]);

// --- cancel / resume -----------------------------------------------------------

it('cancels the auto-renewal at the provider and keeps Pro until the paid period ends, then resumes it', function (): void {
    $learner = Learner::factory()->create();
    $subId = (int) paidOrder($learner, $this->stars)->subscription_id;

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/subscription/cancel')
        ->assertOk()
        ->assertJsonPath('entitlement.plan', 'pro')
        ->assertJsonPath('entitlement.renewal.auto', false)
        ->assertJsonPath('entitlement.renewal.provider', 'telegram_stars');

    expect($this->stars->canceled)->toBe([$subId])
        ->and(Subscription::query()->findOrFail($subId)->status)->toBe('canceled');

    $this->withHeaders(billingHeaders($learner))
        ->getJson('/api/twa/billing/catalog')
        ->assertJsonPath('subscription.status', 'canceled')
        ->assertJsonPath('subscription.can_resume', true)
        ->assertJsonPath('subscription.can_cancel', false);

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/subscription/resume')
        ->assertOk()
        ->assertJsonPath('entitlement.renewal.auto', true);

    expect($this->stars->resumed)->toBe([$subId])
        ->and(Subscription::query()->findOrFail($subId)->status)->toBe('active');
});

it('refuses cancel and resume that make no sense with 409 and does not touch the provider', function (): void {
    $payer = Learner::factory()->create();
    paidOrder($payer, $this->payme);
    $nobody = Learner::factory()->create();
    $renewing = Learner::factory()->create();
    paidOrder($renewing, $this->stars);

    $cases = [
        ['cancel', $nobody, 'not_cancelable'],     // no subscription
        ['cancel', $payer, 'not_cancelable'],      // Payme period: nothing renews
        ['resume', $nobody, 'not_resumable'],
        ['resume', $payer, 'not_resumable'],
        ['resume', $renewing, 'not_resumable'],    // already active
    ];

    foreach ($cases as [$action, $learner, $code]) {
        $this->withHeaders(billingHeaders($learner))
            ->postJson("/api/twa/billing/subscription/{$action}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', $code);
    }

    expect($this->stars->canceled)->toBe([])->and($this->stars->resumed)->toBe([])
        ->and($this->payme->canceled)->toBe([]);
});

it('does not resume after the paid period ended', function (): void {
    $learner = Learner::factory()->create();
    Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'status' => 'canceled',
        'canceled_at' => now()->subDays(2),
        'current_period_ends_at' => now()->subMinute(),
    ]);

    // not live any more: nothing to resume, and the provider is not asked
    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/subscription/resume')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'not_resumable');

    expect($this->stars->resumed)->toBe([]);
});

it('keeps the subscription untouched when the provider fails to cancel (502)', function (): void {
    $learner = Learner::factory()->create();
    $subId = (int) paidOrder($learner, $this->stars)->subscription_id;
    $this->stars->failAutoRenewWith = new RuntimeException('telegram is down');

    $this->withHeaders(billingHeaders($learner))
        ->postJson('/api/twa/billing/subscription/cancel')
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'provider_unavailable');

    expect(Subscription::query()->findOrFail($subId)->status)->toBe('active');
});

// --- paywall mode ----------------------------------------------------------------

it('/me offers purchase only while something is for sale', function (): void {
    $learner = Learner::factory()->create();

    $this->withHeaders(billingHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('paywall.mode', 'purchase');

    FakeBillingProvider::register(SubscriptionProvider::TelegramStars, enabled: false);
    FakeBillingProvider::register(SubscriptionProvider::Payme, enabled: false);

    $this->withHeaders(billingHeaders($learner))->getJson('/api/twa/me')->assertJsonPath('paywall.mode', 'coming_soon');
});

it('/me stays coming_soon while the owner has not switched the paywall on', function (): void {
    config()->set('billing.paywall.mode', 'coming_soon');

    $this->withHeaders(billingHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/me')
        ->assertJsonPath('paywall.mode', 'coming_soon');
});

it('is coming_soon when the providers are bound but no price is set', function (): void {
    config()->set('billing.prices.pro', ['month' => ['XTR' => 0, 'UZS' => 0], 'year' => ['XTR' => 0, 'UZS' => 0]]);

    $this->withHeaders(billingHeaders(Learner::factory()->create()))
        ->getJson('/api/twa/me')
        ->assertJsonPath('paywall.mode', 'coming_soon');
});

// --- billing:expire-orders ---------------------------------------------------------

it('runs billing:expire-orders and has it scheduled every 15 minutes', function (): void {
    $learner = Learner::factory()->create();
    $stale = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');
    $stale->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    $fresh = app(CheckoutService::class)->create($learner, Plan::Pro, 'month', SubscriptionProvider::TelegramStars, null, 'twa');

    Artisan::call('billing:expire-orders');

    expect(Artisan::output())->toContain('Orders expired: 1')
        ->and($stale->refresh()->status)->toBe('expired')
        ->and($fresh->refresh()->status)->toBe('pending');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'billing:expire-orders'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *');
});
