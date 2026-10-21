<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\StarsRenewalStopper;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

/* Repeated review of 2A.8 payments (docs/13 "Реализация 2A.8"): Stars year over a canceled month, duplicates, overlapping Payme/Click, manual grants. */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $this->click = FakeBillingProvider::register(SubscriptionProvider::Click);
    $this->stars = new FakeBillingProvider(SubscriptionProvider::TelegramStars);
    $this->learner = Learner::factory()->create(['interface_locale' => 'ru']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function rrOrder(Learner $learner, SubscriptionProvider $provider = SubscriptionProvider::TelegramStars, string $period = 'month'): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $provider, null, 'twa');
}

function rrPay(PaymentOrder $order, string $chargeId, ?FakeBillingProvider $via = null, int $days = 0): Payment
{
    $via ??= test()->stars;
    $event = $via->emitCharge(
        $order,
        periodEndsAt: CarbonImmutable::now()->addDays($days > 0 ? $days : ($order->period === 'year' ? 365 : 30)),
        eventId: $chargeId,
        raw: ['is_recurring' => $order->period === 'month' && $via === test()->stars],
    );

    return app(PaymentApplier::class)->applyCharge($event);
}

function rrSub(): Subscription
{
    return Subscription::query()->where('subscriber_id', test()->learner->id)->firstOrFail();
}

// --- R1 ------------------------------------------------------------------------------------------------------

it('R1: a Stars year paid over a CANCELED month subscription is a one-off row (not running, not resumable)', function (): void {
    rrPay(rrOrder($this->learner), 'ch_m');
    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);
    rrPay(rrOrder($this->learner, period: 'year'), 'ch_y');

    $stars = rrSub()->meta['stars'];
    expect($stars['charge_id'])->toBe('ch_y')
        ->and($stars['recurring'])->toBeFalse()
        ->and($stars['previous_charge_id'])->toBe('ch_m');

    expect(app(CheckoutService::class)->catalog($this->learner)->subscription['can_cancel'])->toBeFalse();
    expect(rrOrder($this->learner, SubscriptionProvider::Payme)->status)->toBe('pending');

    $edits = count($this->telegram->starSubscriptionEdits);
    try {
        app(LearnerBillingService::class)->resumeAutoRenew($this->learner);
        $code = null;
    } catch (SubscriptionException $e) {
        $code = $e->errorCode;
    }
    expect($code)->toBe(SubscriptionException::NOT_RESUMABLE)
        ->and($this->telegram->starSubscriptionEdits)->toHaveCount($edits);
});

it('R1: a Stars year over a RUNNING month subscription still keeps the Telegram handle', function (): void {
    $month = rrOrder($this->learner);
    $year = rrOrder($this->learner, period: 'year');
    rrPay($month, 'ch_m');
    rrPay($year, 'ch_y');

    expect(rrSub()->meta['stars'])->toBe(['charge_id' => 'ch_m', 'recurring' => true]);
});

// --- R2 + E ----------------------------------------------------------------------------------------------------

function rrDoubleStars(): Subscription
{
    $a = rrOrder(test()->learner);
    $b = rrOrder(test()->learner);
    rrPay($a, 'ch_a');
    rrPay($b, 'ch_b');

    return rrSub();
}

it('R2: cancel moves the duplicates to canceled_duplicate_charge_ids, resume re-enables only the main charge', function (): void {
    rrDoubleStars();
    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);

    $stars = rrSub()->meta['stars'];
    expect($stars['duplicate_charge_ids'] ?? [])->toBe([])
        ->and($stars['canceled_duplicate_charge_ids'])->toBe(['ch_b']);

    $this->telegram->starSubscriptionEdits = [];
    app(LearnerBillingService::class)->resumeAutoRenew($this->learner);
    expect($this->telegram->starSubscriptionEdits)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_a', 'is_canceled' => false]]);
});

it('E: a failing duplicate does not fail cancel; the main charge is canceled first and the duplicate stays for a retry', function (): void {
    rrDoubleStars();
    Log::spy();
    $this->telegram->failStarsOn = 'ch_b';

    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);

    expect(array_column($this->telegram->starSubscriptionEdits, 'charge_id'))->toBe(['ch_a'])
        ->and(rrSub()->status)->toBe('canceled')
        ->and(rrSub()->meta['stars']['duplicate_charge_ids'])->toBe(['ch_b']);
    Log::shouldHaveReceived('warning')->with('billing.stars.duplicate_cancel_failed', Mockery::type('array'))->once();
});

// --- R5 ------------------------------------------------------------------------------------------------------

it('R5: a Payme Perform that was started before a Stars month keeps the Stars provider, extends the period and flags the overlap', function (): void {
    $payme = rrOrder($this->learner, SubscriptionProvider::Payme);
    rrPay(rrOrder($this->learner), 'ch_m');
    $endBefore = rrSub()->current_period_ends_at;
    Log::spy();

    rrPay($payme, 'pm_1', $this->payme, 60);

    $sub = rrSub();
    expect($sub->provider)->toBe('telegram_stars')
        ->and($sub->provider_ref)->toBe('ch_m')
        ->and($sub->meta['stars'])->toBe(['charge_id' => 'ch_m', 'recurring' => true])
        ->and($sub->current_period_ends_at->greaterThan($endBefore))->toBeTrue()
        ->and($sub->meta['overlapping_payments'])->toHaveCount(1)
        ->and($sub->meta['overlapping_payments'][0])->toMatchArray(['provider' => 'payme', 'payment_order_id' => $payme->id]);
    Log::shouldHaveReceived('warning')->with('billing.overlapping_payment_on_stars', Mockery::type('array'))->once();

    app(LearnerBillingService::class)->cancelAutoRenew($this->learner);
    expect(array_column($this->telegram->starSubscriptionEdits, 'charge_id'))->toBe(['ch_m'])
        ->and(rrSub()->status)->toBe('canceled');
});

it('R5: the same for a Click payment', function (): void {
    $click = rrOrder($this->learner, SubscriptionProvider::Click);
    rrPay(rrOrder($this->learner), 'ch_m');

    rrPay($click, 'ck_1', $this->click);

    expect(rrSub()->provider)->toBe('telegram_stars')
        ->and(rrSub()->meta['overlapping_payments'][0]['provider'])->toBe('click');
});

// --- D -------------------------------------------------------------------------------------------------------

it('D: refunding an old payment does not cut a perpetual manual grant on the same row', function (): void {
    $order = rrOrder($this->learner, SubscriptionProvider::Payme);
    $charge = rrPay($order, 'pm_1', $this->payme);
    $admin = User::factory()->create();
    app(SubscriptionService::class)->grantManual($this->learner, Plan::Pro, null, $admin, 'forever');
    expect(rrSub()->current_period_ends_at)->toBeNull();

    app(PaymentApplier::class)->applyRefund($this->payme->emitRefund($charge, eventId: 'pm_1:refund'), $admin);

    $sub = rrSub();
    expect($sub->status)->toBe('active')->and($sub->current_period_ends_at)->toBeNull()->and($sub->provider)->toBe('manual');
});

// --- minor ---------------------------------------------------------------------------------------------------

it('a one-off Stars year gets no grace', function (): void {
    rrPay(rrOrder($this->learner, period: 'year'), 'ch_y');
    $sub = rrSub();
    $sub->update(['current_period_ends_at' => CarbonImmutable::now()->subHour()]);

    expect(app(SubscriptionService::class)->expireDue())->toBeGreaterThan(0)
        ->and(rrSub()->status)->toBe('expired');
});

it('remindExpiring skips running Stars subscriptions in SQL but still reminds a one-off year', function (): void {
    config()->set('billing.checkout.renewal_reminder_days', [3, 1]);
    rrPay(rrOrder($this->learner), 'ch_m');
    $other = Learner::factory()->create();
    rrPay(rrOrder($other, period: 'year'), 'ch_y');
    Subscription::query()->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(2)]);

    expect(app(SubscriptionService::class)->remindExpiring())->toBe(1);
});

it('the system stop of a Stars renewal (Telegram-side refund) is recorded as a system cancel, not a learner one', function (): void {
    rrPay(rrOrder($this->learner), 'ch_m');
    $sub = rrSub();
    $running = ['subscription_id' => $sub->id, 'payment_id' => 1];

    app(StarsRenewalStopper::class)->stop($running);

    expect($sub->refresh()->status)->toBe('canceled');
    $event = ProductEvent::query()->where('name', 'subscription.canceled')->sole();
    expect($event->props['by'])->toBe('system')->and($event->learner_id)->toBe($this->learner->id);
});

it('PaidSubscriptionQuery agrees: a one-off Stars year has no grace days, a month has', function (): void {
    rrPay(rrOrder($this->learner, period: 'year'), 'ch_y');
    $other = Learner::factory()->create();
    rrPay(rrOrder($other), 'ch_m');
    Subscription::query()->update(['current_period_ends_at' => CarbonImmutable::now()->subHour()]);

    $granting = PaidSubscriptionQuery::granting(Subscription::query(), CarbonImmutable::now())->pluck('subscriptions.subscriber_id')->all();

    expect($granting)->toBe([$other->id]);
});
