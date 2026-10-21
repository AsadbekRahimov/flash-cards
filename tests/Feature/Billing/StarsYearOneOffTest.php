<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Providers\StarsPreCheckoutGuard;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ProScreen;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/*
 * Review fixes of 2A.8 (docs/13 "Реализация 2A.8"): a one-off Stars year invoice is not an auto-renewing subscription
 * (`meta.stars.recurring`), and unpaid Stars orders do not pile up forever.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    FakeBillingProvider::register(SubscriptionProvider::Payme);
    FakeBillingProvider::register(SubscriptionProvider::Click);
    $this->starsEvents = new FakeBillingProvider(SubscriptionProvider::TelegramStars);
    $this->learner = Learner::factory()->create(['interface_locale' => 'ru']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function syoOrder(Learner $learner, SubscriptionProvider $provider = SubscriptionProvider::TelegramStars, string $period = 'month'): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $provider, null, 'twa');
}

function syoPay(PaymentOrder $order, string $chargeId, bool $recurringFlag = false): void
{
    $event = test()->starsEvents->emitCharge(
        $order,
        periodEndsAt: CarbonImmutable::now()->addDays($order->period === 'year' ? 365 : 30),
        eventId: $chargeId,
        raw: ['is_recurring' => $recurringFlag],
    );
    app(PaymentApplier::class)->applyCharge($event);
}

function syoYearPaid(): Subscription
{
    syoPay(syoOrder(test()->learner, period: 'year'), 'ch_year');

    return Subscription::query()->where('subscriber_id', test()->learner->id)->firstOrFail();
}

// --- 1: the one-off year ---------------------------------------------------------------------------------

it('records meta.stars.recurring: false for a year invoice, true for a month subscription', function (): void {
    expect(syoYearPaid()->meta['stars'])->toBe(['charge_id' => 'ch_year', 'recurring' => false]);

    $other = Learner::factory()->create();
    syoPay(syoOrder($other), 'ch_month');
    expect(Subscription::query()->where('subscriber_id', $other->id)->firstOrFail()->meta['stars'])->toBe(['charge_id' => 'ch_month', 'recurring' => true]);
});

it('a paid Stars year does not block Payme / Click / Stars year, and has no cancel', function (): void {
    $sub = syoYearPaid();

    foreach ([[SubscriptionProvider::Payme, 'month'], [SubscriptionProvider::Click, 'year'], [SubscriptionProvider::TelegramStars, 'year']] as [$provider, $period]) {
        expect(syoOrder($this->learner, $provider, $period)->status)->toBe('pending');
    }

    $summary = app(CheckoutService::class)->catalog($this->learner)->subscription;
    expect($summary['can_cancel'])->toBeFalse()->and($summary['auto_renew'])->toBeFalse();

    $screen = app(ProScreen::class)->build($this->learner);
    expect(json_encode($screen['markup']))->not->toContain('pro:cancel');

    expect(fn () => app(LearnerBillingService::class)->cancelAutoRenew($this->learner))->toThrow(SubscriptionException::class);
    expect(fn () => app(SubscriptionService::class)->cancel($sub, $this->learner))->toThrow(SubscriptionException::class);
    expect($this->telegram->starSubscriptionEdits)->toBe([]);
});

it('pre_checkout does not refuse a Stars order on top of a paid Stars year', function (): void {
    syoYearPaid();
    $order = syoOrder($this->learner, period: 'year');
    $query = ['id' => 'q', 'from' => ['id' => $this->learner->telegram_user_id], 'currency' => 'XTR', 'total_amount' => $order->amount, 'invoice_payload' => $order->token];

    expect(app(StarsPreCheckoutGuard::class)->check($query)->ok)->toBeTrue();
});

it('a Stars month on top of a long Stars year is subscribe_too_early, and allowed in the last 7 days', function (): void {
    $sub = syoYearPaid();

    $e = null;
    try {
        syoOrder($this->learner);
    } catch (CheckoutException $e) {
    }
    expect($e?->errorCode)->toBe(CheckoutException::SUBSCRIBE_TOO_EARLY);

    $sub->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(5)]);
    expect(syoOrder($this->learner)->status)->toBe('pending');
});

it('a Stars month subscription still blocks every order and is cancelable', function (): void {
    syoPay(syoOrder($this->learner), 'ch_month');

    foreach ([[SubscriptionProvider::Payme, 'month'], [SubscriptionProvider::TelegramStars, 'year']] as [$provider, $period]) {
        $e = null;
        try {
            syoOrder($this->learner, $provider, $period);
        } catch (CheckoutException $e) {
        }
        expect($e?->errorCode)->toBe(CheckoutException::CONFLICT);
    }

    $summary = app(CheckoutService::class)->catalog($this->learner)->subscription;
    expect($summary['can_cancel'])->toBeTrue();

    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);
    expect($this->telegram->starSubscriptionEdits)->toHaveCount(1)
        ->and($this->telegram->starSubscriptionEdits[0]['charge_id'])->toBe('ch_month');
});

it('a Stars year paid on top of a running month subscription keeps the Telegram subscription handle', function (): void {
    $month = syoOrder($this->learner);
    $year = syoOrder($this->learner, period: 'year');
    syoPay($month, 'ch_month');
    syoPay($year, 'ch_year'); // a payment already past pre_checkout

    $sub = Subscription::query()->where('subscriber_id', $this->learner->id)->firstOrFail();
    expect($sub->meta['stars'])->toBe(['charge_id' => 'ch_month', 'recurring' => true]);
});

it('a legacy Stars row without the flag follows the period of its latest paid order', function (): void {
    // Written before the flag: no `recurring`, a paid year order -> one-off.
    $year = Subscription::factory()->create([
        'subscriber_id' => $this->learner->id, 'provider' => 'telegram_stars', 'status' => 'active',
        'current_period_ends_at' => CarbonImmutable::now()->addDays(200), 'meta' => ['stars' => ['charge_id' => 'old_year']],
    ]);
    PaymentOrder::factory()->create(['subscriber_id' => $this->learner->id, 'provider' => 'telegram_stars', 'status' => 'paid', 'period' => 'year', 'subscription_id' => $year->id]);

    expect(app(CheckoutService::class)->catalog($this->learner)->subscription['can_cancel'])->toBeFalse()
        ->and(syoOrder($this->learner, SubscriptionProvider::Payme)->status)->toBe('pending');

    // A paid month order (or none at all) -> renews, as before.
    $monthLearner = Learner::factory()->create();
    $month = Subscription::factory()->create([
        'subscriber_id' => $monthLearner->id, 'provider' => 'telegram_stars', 'status' => 'active',
        'current_period_ends_at' => CarbonImmutable::now()->addDays(20), 'meta' => ['stars' => ['charge_id' => 'old_month']],
    ]);
    PaymentOrder::factory()->create(['subscriber_id' => $monthLearner->id, 'provider' => 'telegram_stars', 'status' => 'paid', 'period' => 'month', 'subscription_id' => $month->id]);

    expect(app(CheckoutService::class)->catalog($monthLearner)->subscription['can_cancel'])->toBeTrue();
    expect(fn () => syoOrder($monthLearner, SubscriptionProvider::Payme))->toThrow(CheckoutException::class);
});

// --- 2: unpaid Stars orders ----------------------------------------------------------------------------------

it('three Stars orders past expires_at do not count against too_many_pending', function (): void {
    for ($i = 0; $i < 3; $i++) {
        syoOrder($this->learner, period: 'year');
    }
    expect(fn () => syoOrder($this->learner, period: 'year'))->toThrow(CheckoutException::class);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));

    expect(syoOrder($this->learner, period: 'year')->status)->toBe('pending');
});

it('the sweep closes a pending Stars order only 7 days after expires_at, and a late payment is still credited', function (): void {
    $stars = syoOrder($this->learner, period: 'year');
    $payme = syoOrder($this->learner, SubscriptionProvider::Payme);
    $service = app(CheckoutService::class);

    // Just past expires_at: Payme is swept, Stars is not.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));
    expect($service->expirePending())->toBe(1)->and($stars->refresh()->status)->toBe('pending');

    // 6 days after expires_at: still not.
    CarbonImmutable::setTestNow($stars->expires_at->addDays(6));
    expect($service->expirePending())->toBe(0)->and($stars->refresh()->status)->toBe('pending');

    CarbonImmutable::setTestNow($stars->expires_at->addDays(7)->addMinute());
    expect($service->expirePending())->toBe(1)->and($stars->refresh()->status)->toBe('expired');
    expect($payme->refresh()->status)->toBe('expired');

    // Whatever still arrives is credited.
    syoPay($stars, 'ch_late');
    expect($stars->refresh()->status)->toBe('paid')
        ->and(Subscription::query()->where('subscriber_id', $this->learner->id)->exists())->toBeTrue();
});

it('reminds about the end of a one-off Stars year, but not of a running Stars subscription', function (): void {
    $year = syoYearPaid();
    $year->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(2)]);
    $other = Learner::factory()->create();
    syoPay(syoOrder($other), 'ch_month');
    Subscription::query()->where('subscriber_id', $other->id)->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(2)]);
    config()->set('billing.checkout.renewal_reminder_days', [3, 1]);

    expect(app(SubscriptionService::class)->remindExpiring())->toBe(1);
});
