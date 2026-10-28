<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Providers\Click\ClickException;
use App\Domain\Billing\Providers\Click\ClickMerchantService;
use App\Domain\Billing\Providers\Payme\PaymeRpcService;
use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Billing\Services\BillingReportService;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\OrganizationOrderGuard;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\ProScreen;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Filament\Resources\PaymentOrderResource\Pages\ListPaymentOrders;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\ClickFixtures;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PaymeFixtures;

/*
 * Review fixes of 2A.8, part A (docs/13 "Реализация 2A.8"): a second Stars subscription / a Payme or Click
 * payment on top of a renewing Stars subscription, a Stars order that "expired" between pre_checkout and
 * successful_payment, a purchase on top of a perpetual / long prepaid period, the Stars renewal that is
 * not stopped by a refund, the charge id that cancel needs after a renewal.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.grace_days', 3);
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    ClickFixtures::configure();
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->click = FakeBillingProvider::register(SubscriptionProvider::Click);
    // The Stars side is the real TelegramStarsProvider on FakeTelegramClient; this fake only fabricates events.
    $this->starsEvents = new FakeBillingProvider(SubscriptionProvider::TelegramStars);
    $this->learner = Learner::factory()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function prfOrder(Learner $learner, SubscriptionProvider $provider = SubscriptionProvider::TelegramStars, string $period = 'month'): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $provider, null, 'twa');
}

/** A Stars payment the way the webhook delivers it. */
function prfPay(PaymentOrder $order, string $chargeId, bool $renewal = false, ?CarbonImmutable $periodEndsAt = null): Payment
{
    $event = test()->starsEvents->emitCharge($order, renewal: $renewal, periodEndsAt: $periodEndsAt ?? CarbonImmutable::now()->addDays(30), eventId: $chargeId);

    return app(PaymentApplier::class)->applyCharge($event);
}

function prfStarsSubscription(Learner $learner, string $status = 'active', string $chargeId = 'ch_live', int $endsInDays = 20): Subscription
{
    return Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'status' => $status,
        'current_period_ends_at' => CarbonImmutable::now()->addDays($endsInDays),
        'grace_until' => $status === 'past_due' ? CarbonImmutable::now()->addDays(2) : null,
        'meta' => ['stars' => ['charge_id' => $chargeId]],
    ]);
}

function prfCheckoutError(callable $call): CheckoutException
{
    try {
        $call();
    } catch (CheckoutException $e) {
        return $e;
    }

    throw new RuntimeException('Expected CheckoutException');
}

/** @return array<string, mixed> */
function prfPreCheckout(PaymentOrder $order, Learner $learner): array
{
    return ['id' => 'pcq-'.$order->id, 'from' => ['id' => $learner->telegram_user_id, 'language_code' => 'ru'], 'currency' => 'XTR', 'total_amount' => $order->amount, 'invoice_payload' => $order->token];
}

// --- 🔴1 (H1): two Stars subscriptions ------------------------------------------------------------

it('H1: two Stars month orders paid in a row -> the second is refused at checkout and pre_checkout, and canceled after the first payment', function (): void {
    $first = prfOrder($this->learner);
    $second = prfOrder($this->learner);
    $year = prfOrder($this->learner, period: 'year');

    prfPay($first, 'ch_a');

    // (c) the other pending Stars month orders are closed by us; a year order is not a subscription.
    expect($second->refresh()->status)->toBe('canceled')
        ->and($second->meta['canceled_reason'])->toBe('already_subscribed')
        ->and($year->refresh()->status)->toBe('pending');

    // (b) pre_checkout refuses what is left (the old invoice link of the canceled order says closed).
    $verdict = app(StarsPreCheckoutGuard::class)->check(prfPreCheckout($year, $this->learner));
    expect($verdict->ok)->toBeFalse()
        ->and($verdict->reason)->toBe('already_subscribed')
        ->and($verdict->errorMessage)->toContain('Stars');
    expect(app(StarsPreCheckoutGuard::class)->check(prfPreCheckout($second, $this->learner))->reason)->toBe('order_closed');

    // (a) checkout: any new order while the Stars renewal runs, the same provider included.
    foreach ([[SubscriptionProvider::TelegramStars, 'month'], [SubscriptionProvider::TelegramStars, 'year'], [SubscriptionProvider::Payme, 'month'], [SubscriptionProvider::Click, 'year']] as [$provider, $period]) {
        $e = prfCheckoutError(fn () => prfOrder($this->learner, $provider, $period));
        expect($e->errorCode)->toBe(CheckoutException::CONFLICT)->and($e->httpStatus())->toBe(409);
    }
});

it('refuses a new order on past_due Stars too, and allows it once the renewal is canceled or the subscription is over', function (): void {
    $sub = prfStarsSubscription($this->learner, 'past_due', endsInDays: -1);

    expect(prfCheckoutError(fn () => prfOrder($this->learner, SubscriptionProvider::Payme))->errorCode)->toBe(CheckoutException::CONFLICT);

    $sub->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now(), 'grace_until' => null, 'current_period_ends_at' => CarbonImmutable::now()->addDays(2)]);
    expect(prfOrder($this->learner, SubscriptionProvider::Payme)->status)->toBe('pending');

    $sub->update(['status' => 'expired', 'ended_at' => CarbonImmutable::now()]);
    expect(prfOrder($this->learner)->status)->toBe('pending');
});

it('H2: Payme / Click pre-checks refuse an order of a learner whose Stars renewal runs (-31055 / -9), without opening a transaction', function (): void {
    $payme = prfOrder($this->learner, SubscriptionProvider::Payme);
    $click = prfOrder($this->learner, SubscriptionProvider::Click);
    prfStarsSubscription($this->learner);

    expect(app(OrganizationOrderGuard::class)->refusal($payme, CarbonImmutable::now()))->toBe(OrganizationOrderGuard::REFUSAL_ALREADY_SUBSCRIBED);

    foreach (['CheckPerformTransaction', 'CreateTransaction'] as $method) {
        $response = app(PaymeRpcService::class)->handle(PaymeFixtures::for($method, $payme));
        expect($response['error']['code'] ?? null)->toBe(-31055)
            ->and($response['error']['data'] ?? null)->toBe('order_id')
            ->and(array_keys($response['error']['message']))->toBe(['ru', 'uz', 'en']);
    }

    $prepare = app(ClickMerchantService::class)->prepare(ClickFixtures::prepare($click));
    expect($prepare['error'])->toBe(ClickException::TRANSACTION_CANCELED)
        ->and(ProviderTransaction::query()->count())->toBe(0)
        ->and($payme->refresh()->status)->toBe('pending');

    // The renewal stopped: the same orders are payable again.
    Subscription::query()->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()]);
    expect(app(PaymeRpcService::class)->handle(PaymeFixtures::for('CheckPerformTransaction', $payme))['result']['allow'] ?? null)->toBeTrue()
        ->and(app(ClickMerchantService::class)->prepare(ClickFixtures::prepare($click))['error'])->toBe(ClickException::SUCCESS);
});

it('(d) credits a second Stars subscription paid anyway (in flight), flags the order, logs it, counts it on the dashboard and keeps both charge ids for cancel', function (): void {
    $first = prfOrder($this->learner);
    $second = prfOrder($this->learner);
    prfPay($first, 'ch_a');
    Log::spy();

    $payment = prfPay($second, 'ch_b');

    $subscription = Subscription::query()->sole();
    expect($payment->status)->toBe('succeeded')
        ->and($second->refresh()->status)->toBe('paid')
        ->and($second->meta['paid_after_cancel'])->toBeTrue()
        ->and($second->meta['stars_double_subscription'])->toBeTrue()
        ->and($first->refresh()->meta)->not->toHaveKey('stars_double_subscription')
        ->and($subscription->meta['stars']['charge_id'])->toBe('ch_a')
        ->and($subscription->meta['stars']['duplicate_charge_ids'])->toBe(['ch_b']);
    Log::shouldHaveReceived('error')->with('billing.stars.double_subscription', Mockery::on(fn (array $ctx): bool => $ctx['payment_order_id'] === $second->id && $ctx['charge_id'] === 'ch_b'))->once();

    expect(app(BillingReportService::class)->webhookErrorsLast24Hours()['stars_double_subscriptions'])->toBe(1);
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Livewire::test(WebhookErrorsWidget::class)->assertSee('Двойные подписки Stars');

    // Cancel stops both Telegram subscriptions.
    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);
    $edited = array_map(static fn (array $edit): string => $edit['charge_id'].':'.($edit['is_canceled'] ? '1' : '0'), $this->telegram->starSubscriptionEdits);
    expect($edited)->toBe(['ch_a:1', 'ch_b:1']);
});

it('(d) shows the double-subscription badge and filter on the payment orders', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $flagged = PaymentOrder::factory()->create(['meta' => ['stars_double_subscription' => true]]);
    $plain = PaymentOrder::factory()->create();

    $this->actingAs($admin);
    Livewire::test(ListPaymentOrders::class)
        ->assertCanSeeTableRecords([$flagged, $plain])
        ->assertSee('двойная подписка')
        ->filterTable('stars_double_subscription')
        ->assertCanSeeTableRecords([$flagged])
        ->assertCanNotSeeTableRecords([$plain]);
});

it('(e) warns in the Grant modal about a renewing Stars subscription and requires a confirmation; the service itself is unchanged', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $sub = prfStarsSubscription($this->learner);
    $this->actingAs($admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $this->learner->id, 'perpetual' => true, 'note' => 'x'])
        ->assertHasActionErrors(['confirm_stars_autorenew']);
    expect($sub->refresh()->provider)->toBe('telegram_stars');

    Livewire::test(ListSubscriptions::class)
        ->mountAction('grantPro')
        ->assertMountedActionModalDontSee('подписка Telegram Stars с автопродлением')
        ->setActionData(['subscriber_type' => 'learner', 'learner_id' => $this->learner->id])
        ->assertMountedActionModalSee('подписка Telegram Stars с автопродлением')
        ->setActionData(['subscriber_type' => 'learner', 'learner_id' => $this->learner->id, 'perpetual' => true, 'note' => 'x', 'confirm_stars_autorenew' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();
    expect($sub->refresh()->provider)->toBe('manual');
});

it('(e) needs no confirmation when the learner has no renewing Stars subscription, and warns when the new end is earlier than the paid one', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    Subscription::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(60)]);
    $this->actingAs($admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $this->learner->id, 'until' => CarbonImmutable::now()->addDays(5)->toDateTimeString(), 'note' => 'shorter'])
        ->assertHasNoActionErrors();

    Subscription::query()->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(60)]);
    Livewire::test(ListSubscriptions::class)
        ->mountAction('grantPro')
        ->setActionData(['subscriber_type' => 'learner', 'learner_id' => $this->learner->id, 'perpetual' => false, 'until' => CarbonImmutable::now()->addDays(5)->toDateTimeString()])
        ->assertMountedActionModalSee('сократит оплаченный период');
});

// --- 🔴2 (H7): the order "expired" between pre_checkout and successful_payment ---------------------------

it('H7: the scheduler leaves a Stars order alone, so the payment after expires_at is credited; a Payme order is still expired', function (): void {
    $stars = prfOrder($this->learner);
    $payme = prfOrder($this->learner, SubscriptionProvider::Payme);
    $later = CarbonImmutable::now()->addHours(25);

    expect(app(CheckoutService::class)->expirePending($later))->toBe(1)
        ->and($stars->refresh()->status)->toBe('pending')
        ->and($payme->refresh()->status)->toBe('expired');

    $payment = prfPay($stars, 'ch_first');
    expect($payment->status)->toBe('succeeded')->and($stars->refresh()->status)->toBe('paid');
});

it('H7: a first Stars payment on an already expired / canceled order is credited with paid_after_cancel; a Payme one is not', function (): void {
    $expired = prfOrder($this->learner);
    $expired->update(['status' => 'expired']);

    $payment = prfPay($expired, 'ch_first');
    expect($payment->status)->toBe('succeeded')
        ->and($expired->refresh()->status)->toBe('paid')
        ->and($expired->meta['paid_after_cancel'])->toBeTrue();

    $other = Learner::factory()->create();
    $dead = prfOrder($other, SubscriptionProvider::Payme);
    $dead->update(['status' => 'expired']);
    expect(fn () => app(PaymentApplier::class)->applyCharge($this->payme->emitCharge($dead)))->toThrow(PaymentApplyException::class);

    // A failed order (no link was ever issued) stays dead for Stars as well.
    $failed = prfOrder(Learner::factory()->create());
    $failed->update(['status' => 'failed']);
    expect(fn () => prfPay($failed, 'ch_failed'))->toThrow(PaymentApplyException::class);
});

it('H7: a Stars renewal is credited whatever the order status says once the order has a succeeded payment; without one it is not', function (): void {
    $order = prfOrder($this->learner);
    prfPay($order, 'ch_first');
    $order->update(['status' => 'expired']);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));
    $renewal = prfPay($order, 'ch_second', renewal: true, periodEndsAt: CarbonImmutable::now()->addDays(30));

    expect($renewal->status)->toBe('succeeded')
        ->and($renewal->kind)->toBe('renewal')
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(30)->toIso8601String())
        ->and($order->refresh()->status)->toBe('paid');

    // An order with no succeeded payment at all is not a subscription anyone paid for.
    $stranger = prfOrder(Learner::factory()->create());
    $stranger->update(['status' => 'expired']);
    expect(fn () => prfPay($stranger, 'ch_x', renewal: true))->toThrow(PaymentApplyException::class);
});

// --- 🟡3: a purchase on top of a long prepaid period / a perpetual grant ----------------------------------

it('refuses every purchase of a learner with a perpetual live subscription (already_perpetual), organizations are not affected', function (): void {
    $grant = Subscription::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'manual', 'current_period_ends_at' => null]);

    foreach ([[SubscriptionProvider::Payme, 'month'], [SubscriptionProvider::TelegramStars, 'month'], [SubscriptionProvider::TelegramStars, 'year'], [SubscriptionProvider::Click, 'year']] as [$provider, $period]) {
        $e = prfCheckoutError(fn () => prfOrder($this->learner, $provider, $period));
        expect($e->errorCode)->toBe(CheckoutException::ALREADY_PERPETUAL)->and($e->httpStatus())->toBe(409);
    }

    $grant->update(['status' => 'expired', 'ended_at' => CarbonImmutable::now()]);
    expect(prfOrder($this->learner, SubscriptionProvider::Payme)->status)->toBe('pending');
});

it('refuses a Stars subscription while a prepaid period runs for longer than the window, and allows it closer to the end; Payme / Click / a Stars year are always allowed', function (): void {
    config()->set('billing.checkout.max_pending_per_subscriber', 10);
    expect(config('billing.stars.subscribe_window_days'))->toBe(7);
    $prepaid = Subscription::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(20)]);

    $e = prfCheckoutError(fn () => prfOrder($this->learner));
    expect($e->errorCode)->toBe(CheckoutException::SUBSCRIBE_TOO_EARLY)
        ->and($e->httpStatus())->toBe(409)
        ->and($e->getMessage())->toContain(CarbonImmutable::now()->addDays(20)->format('Y-m-d'));
    expect(PaymentOrder::query()->count())->toBe(0);

    expect(prfOrder($this->learner, SubscriptionProvider::Payme)->status)->toBe('pending')
        ->and(prfOrder($this->learner, SubscriptionProvider::Click)->status)->toBe('pending')
        ->and(prfOrder($this->learner, period: 'year')->status)->toBe('pending');

    $prepaid->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(7)]);
    expect(prfOrder($this->learner)->status)->toBe('pending');
});

// --- 🟡6 / 🟡7 ----------------------------------------------------------------------------------------------

it('stops the Telegram renewal after an admin refund of a subscription payment, outside any transaction; a failing stop does not break the refund', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $charge = prfPay(prfOrder($this->learner), 'ch_first');
    $observed = [];
    $baseline = DB::transactionLevel();
    $this->telegram->onStarsCall = function (string $method) use (&$observed, $baseline): void {
        $observed[] = [$method, DB::transactionLevel() - $baseline];
    };

    app(PaymentRefundService::class)->refund($charge, $admin, 'ticket');

    expect($this->telegram->starRefunds)->toHaveCount(1)
        ->and($this->telegram->starSubscriptionEdits)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => true]])
        ->and($observed)->toBe([['refundStarPayment', 0], ['editUserStarSubscription', 0]])
        ->and($charge->refresh()->status)->toBe('refunded')
        ->and(Subscription::query()->sole()->status)->toBe('expired');

    // Telegram refuses the stop: the money is back and the ledger says so anyway.
    Log::spy();
    $other = Learner::factory()->create();
    $second = prfPay(prfOrder($other), 'ch_other');
    $this->telegram->onStarsCall = function (string $method): void {
        if ($method === 'editUserStarSubscription') {
            throw new TelegramApiException('Bad Gateway');
        }
    };

    $refund = app(PaymentRefundService::class)->refund($second, $admin, 'ticket 2');

    expect($refund->kind)->toBe('refund')->and($second->refresh()->status)->toBe('refunded');
    Log::shouldHaveReceived('error')->with('billing.refund_autorenew_stop_failed', Mockery::on(fn (array $ctx): bool => $ctx['payment_id'] === $second->id))->once();
});

it('stops the Telegram renewal when Telegram itself reports the refund (refunded_payment); a re-delivery does not call it again', function (): void {
    $charge = prfPay(prfOrder($this->learner), 'ch_first');
    $update = ['update_id' => 70, 'message' => [
        'message_id' => 970,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $this->learner->telegram_user_id, 'type' => 'private'],
        'from' => ['id' => $this->learner->telegram_user_id, 'language_code' => 'ru'],
        'refunded_payment' => ['currency' => 'XTR', 'total_amount' => $charge->amount, 'invoice_payload' => 'x', 'telegram_payment_charge_id' => 'ch_first'],
    ]];

    app(TelegramDispatcher::class)->dispatch($update);
    app(TelegramDispatcher::class)->dispatch($update);

    expect($this->telegram->starSubscriptionEdits)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => true]])
        ->and($charge->refresh()->status)->toBe('refunded');
});

it('keeps the FIRST payment charge id for cancel when a renewal arrives after the subscription row expired', function (): void {
    $order = prfOrder($this->learner);
    prfPay($order, 'ch_first');
    $first = Subscription::query()->sole();
    $first->update(['status' => 'expired', 'ended_at' => CarbonImmutable::now()->addDays(31)]);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(35));
    prfPay($order, 'ch_late_renewal', renewal: true, periodEndsAt: CarbonImmutable::now()->addDays(30));

    $revived = Subscription::query()->whereKeyNot($first->id)->sole();
    expect($revived->status)->toBe('active')
        ->and($revived->meta['stars']['charge_id'])->toBe('ch_first')
        ->and($revived->provider_ref)->toBe('ch_late_renewal');

    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);
    expect($this->telegram->starSubscriptionEdits)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => true]]);
});

// --- small things -------------------------------------------------------------------------------------------

it('keeps a cancel reason written while the provider link was being created', function (): void {
    $this->telegram->onStarsCall = function (string $method): void {
        if ($method === 'createInvoiceLink') {
            $order = PaymentOrder::query()->sole();
            $order->update(['status' => 'canceled', 'meta' => [...$order->meta, 'canceled_reason' => 'offer_moved']]);
        }
    };

    $order = prfOrder($this->learner);

    expect($order->refresh()->meta['canceled_reason'])->toBe('offer_moved')
        ->and($order->meta['checkout']['kind'])->toBe('invoice_link')
        ->and($order->provider_ref)->not->toBeNull();
});

it('summarizes a perpetual subscription for the catalog', function (): void {
    Subscription::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'manual', 'current_period_ends_at' => null]);

    $summary = app(CheckoutService::class)->catalog($this->learner)->subscription;

    expect($summary)->not->toBeNull()
        ->and($summary['perpetual'])->toBeTrue();
});

it('/pro shows no buy buttons for a perpetual Pro, and still shows them for a prepaid period', function (): void {
    $sub = Subscription::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'manual', 'current_period_ends_at' => null]);

    $screen = app(ProScreen::class)->build($this->learner);
    expect($screen['markup'])->toBeNull()
        ->and(app(ProScreen::class)->buyRows($this->learner))->toBe([]);

    $sub->update(['provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(20)]);
    expect(app(ProScreen::class)->build($this->learner)['markup'])->not->toBeNull()
        ->and(app(ProScreen::class)->buyRows($this->learner))->not->toBe([]);
});

it('has a grantManual that is unchanged: it replaces a renewing Stars subscription with a manual grant', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    prfStarsSubscription($this->learner);

    $grant = app(SubscriptionService::class)->grantManual($this->learner, Plan::Pro, CarbonImmutable::now()->addDays(60), $admin, 'x');

    expect($grant->provider)->toBe('manual')->and(Subscription::query()->count())->toBe(1);
});

it('a reward on top of a canceled Stars subscription keeps it canceled: a Payme purchase is not blocked', function (): void {
    prfPay(prfOrder($this->learner), 'ch_first');
    $sub = Subscription::query()->sole();
    app(SubscriptionService::class)->cancel($sub, $this->learner);

    DB::transaction(fn () => app(SubscriptionService::class)->grantReward($this->learner, 7, 'referral:1', CarbonImmutable::now()));

    expect($sub->refresh()->status)->toBe('canceled')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(37)->toIso8601String());

    $order = prfOrder($this->learner, SubscriptionProvider::Payme);
    expect($order->status)->toBe('pending');
});

it('reward days on top of a RUNNING Stars subscription do not carry over: the next renewal sets the end from Telegram (documented, docs/13 Спорное)', function (): void {
    prfPay(prfOrder($this->learner), 'ch_first', periodEndsAt: CarbonImmutable::now()->addDays(30));
    $sub = Subscription::query()->sole();
    $originalEnd = CarbonImmutable::now()->addDays(30);

    DB::transaction(fn () => app(SubscriptionService::class)->grantReward($this->learner, 7, 'referral:2', CarbonImmutable::now()));
    expect($sub->refresh()->status)->toBe('active')
        ->and($sub->current_period_ends_at?->toIso8601String())->toBe($originalEnd->addDays(7)->toIso8601String());

    CarbonImmutable::setTestNow($originalEnd);
    prfPay(PaymentOrder::query()->sole(), 'ch_second', renewal: true, periodEndsAt: $originalEnd->addDays(30));

    // 30 days from the original end, NOT +7 on top: the reward insured the first period only.
    expect($sub->refresh()->current_period_ends_at?->toIso8601String())->toBe($originalEnd->addDays(30)->toIso8601String());
});
