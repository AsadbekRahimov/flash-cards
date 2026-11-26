<?php

declare(strict_types=1);

/*
 * Buying Pro during the welcome trial (owner's decision docs/31 §5г; docs/13 §7.1 "После пробного Pro"): allowed from the first day for
 * every period and provider, the paid time is added after the trial. A Stars month carries the rest of the trial past every period of
 * that Telegram subscription (`meta.stars.grant_carry_seconds`); a re-delivered payment changes nothing; the window
 * `subscribe_too_early` still protects a PAID period. The real TelegramStarsProvider talks to FakeTelegramClient.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Providers\TelegramStarsProvider;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.stars.subscribe_window_days', 7);
    config()->set('twa.base_url', 'https://lexiflow.test');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->learner = Learner::factory()->onboarded()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
    // Day 1 of a 14-day trial: 13 days and 12 hours are left.
    $this->trialEnd = CarbonImmutable::now()->addDays(13)->addHours(12);
    $this->trial = app(SubscriptionService::class)->grantTrial($this->learner, 14, CarbonImmutable::now()->subHours(12));
    $this->trial->update(['current_period_ends_at' => $this->trialEnd]);
    app(EntitlementResolver::class)->forget($this->learner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function trialBuy(Learner $learner, string $period = 'month', SubscriptionProvider $provider = SubscriptionProvider::TelegramStars): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, $provider, null, 'twa');
}

/** @return array<string, mixed> a `message` update with `successful_payment` (Bot API names) */
function trialStarsPaid(PaymentOrder $order, string $chargeId, int $fromId, CarbonImmutable $expiration, bool $first = true, int $updateId = 1, bool $subscription = true): array
{
    $payment = [
        'currency' => 'XTR',
        'total_amount' => $order->amount,
        'invoice_payload' => $order->token,
        'telegram_payment_charge_id' => $chargeId,
        'provider_payment_charge_id' => 'prov_'.$chargeId,
    ];
    if ($subscription) {
        $payment += ['subscription_expiration_date' => $expiration->getTimestamp(), 'is_recurring' => true] + ($first ? ['is_first_recurring' => true] : []);
    }

    return ['update_id' => $updateId, 'message' => [
        'message_id' => 700 + $updateId,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'successful_payment' => $payment,
    ]];
}

/** @return array<string, mixed> */
function trialStarsRefunded(string $chargeId, int $fromId, int $amount): array
{
    return ['update_id' => 90, 'message' => [
        'message_id' => 790,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'refunded_payment' => ['currency' => 'XTR', 'total_amount' => $amount, 'invoice_payload' => 'x', 'telegram_payment_charge_id' => $chargeId],
    ]];
}

function trialEnds(Learner $learner): string
{
    return CarbonImmutable::instance(Subscription::query()->where('subscriber_id', $learner->id)->sole()->current_period_ends_at)->toIso8601String();
}

it('sells a Stars month on the first day of the trial and adds the 30 paid days after the trial', function (): void {
    $order = trialBuy($this->learner);
    expect($order->status)->toBe('pending');

    $telegramEnd = CarbonImmutable::now()->addDays(30);
    app(TelegramDispatcher::class)->dispatch(trialStarsPaid($order, 'ch_first', $this->learner->telegram_user_id, $telegramEnd));

    $subscription = Subscription::query()->sole();
    $payment = Payment::query()->sole();
    $expectedEnd = $this->trialEnd->addDays(30);

    expect($subscription->id)->toBe($this->trial->id)
        ->and($subscription->provider)->toBe('telegram_stars')
        ->and($subscription->status)->toBe('active')
        ->and(CarbonImmutable::instance($subscription->current_period_ends_at)->toIso8601String())->toBe($expectedEnd->toIso8601String())
        ->and($subscription->meta['stars'])->toEqual(['charge_id' => 'ch_first', 'recurring' => true, 'grant_carry_seconds' => 13 * 86400 + 12 * 3600])
        // The charge pays for the 30 days after the trial.
        ->and(CarbonImmutable::instance($payment->period_starts_at)->toIso8601String())->toBe($this->trialEnd->toIso8601String())
        ->and(CarbonImmutable::instance($payment->period_ends_at)->toIso8601String())->toBe($expectedEnd->toIso8601String())
        // Pro lasts to the new end (+ the grace of an auto-renewing Stars row).
        ->and(app(EntitlementResolver::class)->for($this->learner)->until?->toIso8601String())->toBe($expectedEnd->addDays((int) config('billing.grace_days'))->toIso8601String());
});

it('applies a re-delivered first payment and a re-delivered renewal once, and keeps the trial on every renewal', function (): void {
    $order = trialBuy($this->learner);
    $dispatcher = app(TelegramDispatcher::class);
    $telegramEnd = CarbonImmutable::now()->addDays(30);

    $dispatcher->dispatch(trialStarsPaid($order, 'ch_first', $this->learner->telegram_user_id, $telegramEnd));
    $dispatcher->dispatch(trialStarsPaid($order, 'ch_first', $this->learner->telegram_user_id, $telegramEnd, updateId: 2));

    expect(Payment::query()->count())->toBe(1)
        ->and(trialEnds($this->learner))->toBe($this->trialEnd->addDays(30)->toIso8601String());

    // Telegram renews on ITS date (30 days after the purchase), while the learner still has the trial's 13.5 days of Pro ahead.
    CarbonImmutable::setTestNow($telegramEnd->subMinutes(5));
    $dispatcher->dispatch(trialStarsPaid($order, 'ch_second', $this->learner->telegram_user_id, $telegramEnd->addDays(30), first: false, updateId: 3));
    $dispatcher->dispatch(trialStarsPaid($order, 'ch_second', $this->learner->telegram_user_id, $telegramEnd->addDays(30), first: false, updateId: 4));

    expect(Payment::query()->count())->toBe(2)
        ->and(Payment::query()->where('provider_event_id', 'ch_second')->sole()->kind)->toBe('renewal')
        ->and(trialEnds($this->learner))->toBe($this->trialEnd->addDays(60)->toIso8601String())
        ->and(Subscription::query()->sole()->meta['stars']['grant_carry_seconds'])->toBe(13 * 86400 + 12 * 3600);

    // The direct re-delivery to the applier is answered as a duplicate with no change either.
    $e = app(TelegramStarsProvider::class)->parseSuccessfulPayment(trialStarsPaid($order, 'ch_second', $this->learner->telegram_user_id, $telegramEnd->addDays(30), first: false)['message']);
    expect(fn () => app(PaymentApplier::class)->applyCharge($e))->toThrow(DuplicatePaymentException::class)
        ->and(trialEnds($this->learner))->toBe($this->trialEnd->addDays(60)->toIso8601String());
});

it('gives back exactly the 30 paid days on a refund of the first charge: the trial stays', function (): void {
    $order = trialBuy($this->learner);
    app(TelegramDispatcher::class)->dispatch(trialStarsPaid($order, 'ch_first', $this->learner->telegram_user_id, CarbonImmutable::now()->addDays(30)));

    app(TelegramDispatcher::class)->dispatch(trialStarsRefunded('ch_first', $this->learner->telegram_user_id, $order->amount));

    $subscription = Subscription::query()->sole();
    expect(Payment::query()->where('provider_event_id', 'ch_first')->sole()->status)->toBe('refunded')
        ->and(CarbonImmutable::instance($subscription->current_period_ends_at)->toIso8601String())->toBe($this->trialEnd->toIso8601String())
        ->and(app(EntitlementResolver::class)->for($this->learner)->plan)->toBe(Plan::Pro);
});

it('adds a one-off Stars quarter and a Payme month after the trial too', function (): void {
    $quarter = trialBuy($this->learner, 'quarter');
    app(TelegramDispatcher::class)->dispatch(trialStarsPaid($quarter, 'ch_q', $this->learner->telegram_user_id, CarbonImmutable::now(), subscription: false));
    expect(trialEnds($this->learner))->toBe($this->trialEnd->addDays(90)->toIso8601String())
        ->and(Subscription::query()->sole()->meta['stars'])->not->toHaveKey('grant_carry_seconds');

    // Another learner on day 1 of the trial pays in sums.
    $payme = FakeBillingProvider::register(SubscriptionProvider::Payme);
    $other = Learner::factory()->create();
    app(SubscriptionService::class)->grantTrial($other, 14, CarbonImmutable::now());
    $otherTrialEnd = CarbonImmutable::now()->addDays(14);

    $order = trialBuy($other, 'month', SubscriptionProvider::Payme);
    app(PaymentApplier::class)->applyCharge($payme->emitCharge($order));

    expect(trialEnds($other))->toBe($otherTrialEnd->addDays(30)->toIso8601String());
});

it('sells a Stars month over other gifts at once but keeps the window over a paid period', function (): void {
    // A promo code on top of the trial turns the row into a promo one: still a gift, still allowed.
    $this->trial->update(['provider' => 'promo', 'status' => 'active']);
    expect(trialBuy($this->learner)->status)->toBe('pending');

    // Paid sums with 20 days left: Telegram would start its own 30 days at once - refused as before.
    $this->trial->update(['provider' => 'payme', 'current_period_ends_at' => CarbonImmutable::now()->addDays(20)]);
    PaymentOrder::query()->update(['status' => 'canceled']);
    try {
        trialBuy($this->learner);
        $this->fail('Expected subscribe_too_early');
    } catch (CheckoutException $e) {
        expect($e->errorCode)->toBe(CheckoutException::SUBSCRIBE_TOO_EARLY);
    }

    // Within the window it is allowed.
    $this->trial->update(['current_period_ends_at' => CarbonImmutable::now()->addDays(5)]);
    expect(trialBuy($this->learner)->status)->toBe('pending');
});

it('carries nothing over a paid period and nothing when the trial is already over', function (): void {
    // A trial that ended an hour ago (not swept yet): a fresh subscription from the payment, no carry.
    $this->trial->update(['current_period_ends_at' => CarbonImmutable::now()->subHour()]);
    app(EntitlementResolver::class)->forget($this->learner);
    $order = trialBuy($this->learner);
    $telegramEnd = CarbonImmutable::now()->addDays(30);
    app(TelegramDispatcher::class)->dispatch(trialStarsPaid($order, 'ch_late', $this->learner->telegram_user_id, $telegramEnd));

    $live = Subscription::query()->where('subscriber_id', $this->learner->id)->where('provider', 'telegram_stars')->sole();
    expect(CarbonImmutable::instance($live->current_period_ends_at)->toIso8601String())->toBe($telegramEnd->toIso8601String())
        ->and($live->meta['stars'] ?? [])->not->toHaveKey('grant_carry_seconds');
});
