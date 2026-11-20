<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Billing\Services\BillingFunnelService;
use App\Domain\Billing\Services\BillingReportService;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\BillingPeriods;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgBillingService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ProScreen;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\JwtService;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Jobs\HandleTelegramUpdate;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/*
 * Pro for 3 months (`quarter`, owner's decision of 20.11.2026, docs/13 §5): 90 days for 390 Stars, a one-off Stars
 * invoice; never sold in sums (no UZS price row) and never to organizations (`billing.org_periods`). The real
 * TelegramStarsProvider talks to FakeTelegramClient; Payme is a fake adapter so that the UZS refusal is about the
 * period, not about a provider that is off.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->learner = Learner::factory()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, string> */
function pqHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

function pqOrder(Learner $learner, string $period = 'quarter', SubscriptionProvider $provider = SubscriptionProvider::TelegramStars): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $provider, null, CheckoutService::VIA_TWA);
}

/** @return array<string, mixed> A one-off `successful_payment` (no subscription fields) as Telegram sends it. */
function pqPaidUpdate(PaymentOrder $order, string $chargeId, int $fromId, ?int $amount = null, int $updateId = 1): array
{
    return ['update_id' => $updateId, 'message' => [
        'message_id' => 700 + $updateId,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => 'ru'],
        'successful_payment' => [
            'currency' => 'XTR',
            'total_amount' => $amount ?? $order->amount,
            'invoice_payload' => $order->token,
            'telegram_payment_charge_id' => $chargeId,
            'provider_payment_charge_id' => 'prov_'.$chargeId,
        ],
    ]];
}

/** @return array<string, mixed> */
function pqPreCheckout(PaymentOrder $order, int $fromId, ?int $amount = null): array
{
    return ['id' => 'pcq-'.$order->id, 'from' => ['id' => $fromId, 'language_code' => 'ru'], 'currency' => 'XTR', 'total_amount' => $amount ?? $order->amount, 'invoice_payload' => $order->token];
}

// --- config ----------------------------------------------------------------------------------------

it('configures the quarter as 90 days for 390 Stars, with no price in sums, for learners only', function (): void {
    expect(config('billing.periods.quarter'))->toBe(90)
        ->and(config('billing.prices.pro.quarter'))->toBe(['XTR' => 390])
        ->and(BillingPeriods::keys())->toBe(['month', 'quarter', 'year'])
        ->and(BillingPeriods::forOrganizations())->toBe(['month', 'year'])
        ->and(BillingPeriods::days('quarter'))->toBe(90)
        ->and(BillingPeriods::months('quarter'))->toBe(3)
        ->and(BillingPeriods::months('year'))->toBe(12)
        ->and(BillingPeriods::months('month'))->toBe(1)
        ->and(SubscriptionService::periodDays('quarter'))->toBe(90)
        ->and(BillingPeriods::listsCurrency('pro', 'quarter', 'XTR'))->toBeTrue()
        ->and(BillingPeriods::listsCurrency('pro', 'quarter', 'UZS'))->toBeFalse()
        ->and(BillingPeriods::listsCurrency('pro', 'month', 'UZS'))->toBeTrue();
});

it('reads the quarter price from BILLING_PRICE_PRO_QUARTER_XTR, 390 when it is not set', function (): void {
    $config = (function (?string $value): array {
        if ($value === null) {
            unset($_ENV['BILLING_PRICE_PRO_QUARTER_XTR'], $_SERVER['BILLING_PRICE_PRO_QUARTER_XTR']);
            putenv('BILLING_PRICE_PRO_QUARTER_XTR');
        } else {
            $_ENV['BILLING_PRICE_PRO_QUARTER_XTR'] = $_SERVER['BILLING_PRICE_PRO_QUARTER_XTR'] = $value;
            putenv('BILLING_PRICE_PRO_QUARTER_XTR='.$value);
        }

        return require config_path('billing.php');
    });

    try {
        expect($config('450')['prices']['pro']['quarter'])->toBe(['XTR' => 450])
            ->and($config('0')['prices']['pro']['quarter'])->toBe(['XTR' => 0])
            ->and($config(null)['prices']['pro']['quarter'])->toBe(['XTR' => 390]);
    } finally {
        unset($_ENV['BILLING_PRICE_PRO_QUARTER_XTR'], $_SERVER['BILLING_PRICE_PRO_QUARTER_XTR']);
        putenv('BILLING_PRICE_PRO_QUARTER_XTR');
    }
});

it('computes the saving versus the month like the Mini App did for the year: UZS first, else Stars, 0 for the month', function (): void {
    expect(BillingPeriods::savingPct('quarter', ['XTR' => 390], ['XTR' => 150, 'UZS' => 2_900_000]))->toBe(13)
        ->and(BillingPeriods::savingPct('year', ['XTR' => 1_300, 'UZS' => 24_900_000], ['XTR' => 150, 'UZS' => 2_900_000]))->toBe(28)
        ->and(BillingPeriods::savingPct('year', ['XTR' => 1_300], ['XTR' => 150]))->toBe(28)
        ->and(BillingPeriods::savingPct('month', ['XTR' => 150], ['XTR' => 150]))->toBe(0)
        // No month to compare with, no common currency, a period dearer than its months: 0.
        ->and(BillingPeriods::savingPct('quarter', ['XTR' => 390], []))->toBe(0)
        ->and(BillingPeriods::savingPct('quarter', ['XTR' => 390], ['UZS' => 2_900_000]))->toBe(0)
        ->and(BillingPeriods::savingPct('quarter', ['XTR' => 500], ['XTR' => 150]))->toBe(0);
});

// --- catalog: API, bot -------------------------------------------------------------------------------

it('lists the quarter in the Mini App catalog for Stars only, with its days and saving, between the month and the year', function (): void {
    $this->withHeaders(pqHeaders($this->learner))->getJson('/api/twa/billing/catalog')
        ->assertOk()
        ->assertJsonPath('providers', ['telegram_stars', 'payme'])
        ->assertJsonPath('items.0.period', 'month')
        ->assertJsonPath('items.0.days', 30)
        ->assertJsonPath('items.0.saving_pct', 0)
        ->assertJsonPath('items.1', [
            'plan' => 'pro', 'tier' => null, 'period' => 'quarter', 'days' => 90,
            'prices' => ['XTR' => 390], 'list_prices' => ['XTR' => 390], 'saving_pct' => 13,
        ])
        ->assertJsonPath('items.2.period', 'year')
        ->assertJsonPath('items.2.days', 365)
        ->assertJsonPath('items.2.saving_pct', 28)
        ->assertJsonMissingPath('items.1.prices.UZS');
});

it('drops the quarter from the catalog while its Stars price is 0, and the saving follows the configured prices', function (): void {
    config()->set('billing.prices.pro.quarter.XTR', 0);
    expect(array_column(app(CheckoutService::class)->catalog($this->learner)->items, 'period'))->toBe(['month', 'year']);

    config()->set('billing.prices.pro.quarter.XTR', 300);
    config()->set('billing.providers.payme.enabled', false);
    $quarter = app(CheckoutService::class)->catalog($this->learner)->items[1];
    expect($quarter['period'])->toBe('quarter')->and($quarter['saving_pct'])->toBe(33);
});

it('offers the quarter for Stars only in /pro of the bot', function (): void {
    $screen = app(ProScreen::class)->build($this->learner);
    $json = (string) json_encode($screen['markup'], JSON_UNESCAPED_UNICODE);

    expect($screen['text'])->toContain('3 месяца — 390 ⭐')
        ->and($json)->toContain('pro1:buy:quarter:telegram_stars')
        ->and($json)->not->toContain('pro1:buy:quarter:payme');
});

it('never offers the quarter to an organization: not in its catalog, refused at checkout, not in the /org page', function (): void {
    $organization = Organization::factory()->create();

    expect(array_unique(array_column(app(CheckoutService::class)->catalog($organization)->items, 'period')))->not->toContain('quarter');

    $e = null;
    try {
        app(CheckoutService::class)->create($organization, Plan::Pro, 'quarter', SubscriptionProvider::Payme, 'tutor', CheckoutService::VIA_ORG_PANEL);
    } catch (CheckoutException $e) {
    }
    expect($e?->errorCode)->toBe(CheckoutException::INVALID_PERIOD)
        ->and(PaymentOrder::query()->count())->toBe(0);

    $owner = User::factory()->create(['is_active' => true]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);
    expect(app(OrgBillingService::class)->page($owner, $organization)->periods)->toBe(['month', 'year']);

    $e = null;
    try {
        app(OrgBillingService::class)->checkout($owner, $organization, 'tutor', 'quarter', SubscriptionProvider::Payme);
    } catch (CheckoutException $e) {
    }
    expect($e?->errorCode)->toBe(CheckoutException::INVALID_PERIOD);
});

// --- the refusal in sums -------------------------------------------------------------------------

it('refuses the quarter through Payme / Click with a validation error on period, and opens no order', function (string $provider): void {
    FakeBillingProvider::register(SubscriptionProvider::Click);

    $this->withHeaders(pqHeaders($this->learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'quarter', 'provider' => $provider])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['period' => 'Срок «3 месяца» нельзя оплатить через']);

    expect(PaymentOrder::query()->count())->toBe(0);
})->with(['payme', 'click']);

it('refuses the quarter in sums in the checkout service too (the bot, a forged callback): invalid_period', function (): void {
    $e = null;
    try {
        pqOrder($this->learner, provider: SubscriptionProvider::Payme);
    } catch (CheckoutException $e) {
    }

    expect($e?->errorCode)->toBe(CheckoutException::INVALID_PERIOD)
        ->and($e?->httpStatus())->toBe(422)
        ->and($e?->getMessage())->toContain('not sold in UZS')
        ->and(PaymentOrder::query()->count())->toBe(0);
});

it('keeps a month without a price in sums a 409 price_not_configured, not a validation error', function (): void {
    config()->set('billing.prices.pro.month.UZS', 0);

    $this->withHeaders(pqHeaders($this->learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'month', 'provider' => 'payme'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'price_not_configured');
});

it('rejects an unknown period with a validation error', function (): void {
    $this->withHeaders(pqHeaders($this->learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'week', 'provider' => 'telegram_stars'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['period']);
});

// --- the Stars path ---------------------------------------------------------------------------------

it('pays a Stars quarter end to end: order, one-off invoice, pre_checkout, successful_payment, 90 days, once', function (): void {
    $response = $this->withHeaders(pqHeaders($this->learner))
        ->postJson('/api/twa/billing/checkout', ['plan' => 'pro', 'period' => 'quarter', 'provider' => 'telegram_stars'])
        ->assertCreated()
        ->assertJsonPath('order.amount', 390)
        ->assertJsonPath('order.currency', 'XTR');

    $order = PaymentOrder::query()->sole();
    expect($order->period)->toBe('quarter')
        ->and($order->amount)->toBe(390)
        ->and($order->list_amount)->toBe(390)
        ->and($response->json('checkout.url'))->toBe($order->provider_ref)
        ->and($this->telegram->invoiceLinks)->toHaveCount(1)
        ->and($this->telegram->invoiceLinks[0]['subscription_period'])->toBeNull()
        ->and($this->telegram->invoiceLinks[0]['prices'])->toBe([['label' => 'LexiFlow Pro, 3 месяца', 'amount' => 390]])
        ->and($this->telegram->invoiceLinks[0]['title'])->toBe('LexiFlow Pro — 3 месяца')
        ->and($this->telegram->invoiceLinks[0]['description'])->toStartWith('LexiFlow Pro на 3 месяца (90 дней)')
        ->and(ProductEvent::query()->where('name', 'checkout.started')->sole()->props['period'])->toBe('quarter');

    // pre_checkout: the amount of the order (= the configured price) passes, any other is refused.
    $guard = app(StarsPreCheckoutGuard::class);
    $me = $this->learner->telegram_user_id;
    expect($guard->check(pqPreCheckout($order, $me, amount: 389))->reason)->toBe(StarsPreCheckoutGuard::REASON_AMOUNT_MISMATCH)
        ->and($guard->check(pqPreCheckout($order, $me, amount: 150))->reason)->toBe(StarsPreCheckoutGuard::REASON_AMOUNT_MISMATCH)
        ->and($guard->check(pqPreCheckout($order, $me))->ok)->toBeTrue();

    $update = pqPaidUpdate($order, 'ch_quarter', $me);
    $dispatcher = app(TelegramDispatcher::class);
    $dispatcher->dispatch($update);

    $subscription = Subscription::query()->sole();
    $ends = CarbonImmutable::now()->addDays(90);
    expect($order->refresh()->status)->toBe('paid')
        ->and(Payment::query()->sole()->amount)->toBe(390)
        ->and($subscription->current_period_ends_at?->toIso8601String())->toBe($ends->toIso8601String())
        ->and($subscription->meta['stars'])->toBe(['charge_id' => 'ch_quarter', 'recurring' => false])
        ->and($this->telegram->sent[array_key_last($this->telegram->sent)]['text'])->toContain($ends->setTimezone('Asia/Tashkent')->format('d.m.Y'));

    // Telegram re-delivers the same update (webhook retry, the queued job): nothing changes.
    $sent = count($this->telegram->sent);
    $dispatcher->dispatch($update);
    (new HandleTelegramUpdate($update))->handle($dispatcher);

    expect(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe($ends->toIso8601String())
        ->and(count(Subscription::query()->sole()->meta['history']))->toBe(1)
        ->and($this->telegram->sent)->toHaveCount($sent)
        ->and(ProductEvent::query()->where('name', 'payment.succeeded')->count())->toBe(1);

    // A one-off quarter does not renew: nothing to cancel, and the next purchase is allowed.
    expect(app(CheckoutService::class)->catalog($this->learner)->subscription['auto_renew'])->toBeFalse();
});

it('stacks a second quarter on top of a live one: +90 days from the end, not from now', function (): void {
    $dispatcher = app(TelegramDispatcher::class);
    $me = $this->learner->telegram_user_id;
    $dispatcher->dispatch(pqPaidUpdate(pqOrder($this->learner), 'ch_q1', $me));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(10));
    $dispatcher->dispatch(pqPaidUpdate(pqOrder($this->learner), 'ch_q2', $me, updateId: 2));

    expect(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC')->addDays(180)->toIso8601String())
        ->and(Payment::query()->count())->toBe(2);
});

it('does not credit a successful_payment with a wrong amount for a quarter', function (): void {
    $order = pqOrder($this->learner);

    app(TelegramDispatcher::class)->dispatch(pqPaidUpdate($order, 'ch_short', $this->learner->telegram_user_id, amount: 150));

    expect(Payment::query()->sole()->raw['failure_reason'])->toBe('amount_mismatch')
        ->and(Subscription::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe('pending');
});

it('is a one-off purchase over a running Stars month: refused like the year (checkout_conflict)', function (): void {
    $stars = new FakeBillingProvider(SubscriptionProvider::TelegramStars);
    $month = pqOrder($this->learner, 'month');
    app(PaymentApplier::class)->applyCharge($stars->emitCharge($month, periodEndsAt: CarbonImmutable::now()->addDays(30), raw: ['is_recurring' => true]));

    $e = null;
    try {
        pqOrder($this->learner);
    } catch (CheckoutException $e) {
    }

    expect($e?->errorCode)->toBe(CheckoutException::CONFLICT);
});

// --- reports and the admin panel -----------------------------------------------------------------------

it('counts a quarter payment in the revenue report and in the Pro funnel', function (): void {
    $order = pqOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(pqPaidUpdate($order, 'ch_quarter', $this->learner->telegram_user_id));

    $cash = app(BillingReportService::class)->cashFlowLast30Days();
    expect($cash['XTR']['gross'])->toBe(390)->and($cash['XTR']['charge_count'])->toBe(1);

    $report = app(BillingFunnelService::class)->compute(CarbonImmutable::now()->addMinute());
    // No offer view was recorded: the start and the payment are "outside" the chain, but counted.
    $outside = collect($report['outside'])->firstWhere('provider', 'telegram_stars');
    expect($outside)->not->toBeNull()
        ->and($outside['started'])->toBe(1)
        ->and($outside['paid'])->toBe(1)
        ->and(collect($report['orders'])->firstWhere('status', 'paid')['orders'] ?? null)->toBe(1);
});

it('shows the quarter as «3 месяца» in the orders list of the admin panel', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $order = PaymentOrder::factory()->create(['period' => 'quarter', 'provider' => 'telegram_stars', 'currency' => 'XTR', 'amount' => 390]);

    Livewire::test(ListPaymentOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertTableColumnFormattedStateSet('period', '3 месяца', $order);
});
