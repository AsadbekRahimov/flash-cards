<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\PaymentRefundException;
use App\Domain\Billing\Exceptions\ProviderUnavailableException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\PaymentRefundService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Jobs\HandleTelegramUpdate;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProductEvent;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;

/*
 * Telegram Stars end to end through the bot (docs/13 §4.1): `successful_payment` (first / recurring /
 * year) credits once, `refunded_payment` refunds, cancel / resume and the admin refund call the Bot
 * API outside any transaction. The real TelegramStarsProvider talks to FakeTelegramClient.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    config()->set('billing.providers.telegram_stars.enabled', true);
    config()->set('billing.paywall.mode', 'purchase');
    config()->set('billing.grace_days', 3);
    config()->set('twa.base_url', 'https://lexiflow.test');
    $this->telegram = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->telegram);
    $this->learner = Learner::factory()->create(['interface_locale' => 'ru', 'timezone' => 'Asia/Tashkent']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function starsOrder(Learner $learner, string $period = 'month'): PaymentOrder
{
    return app(CheckoutService::class)->create($learner, Plan::Pro, $period, SubscriptionProvider::TelegramStars, null, 'twa');
}

/** @return array<string, mixed> A `message` update carrying `successful_payment` (Bot API field names — docs/13 §15). */
function starsPaidUpdate(PaymentOrder $order, string $chargeId, int $fromId, bool $subscription = true, bool $first = true, ?int $expiration = null, ?int $amount = null, int $updateId = 1): array
{
    $payment = [
        'currency' => 'XTR',
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

    return ['update_id' => $updateId, 'message' => [
        'message_id' => 900 + $updateId,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => 'ru'],
        'successful_payment' => $payment,
    ]];
}

/** @return array<string, mixed> */
function starsRefundUpdate(string $chargeId, int $fromId, int $amount, int $updateId = 50): array
{
    return ['update_id' => $updateId, 'message' => [
        'message_id' => 900 + $updateId,
        'date' => CarbonImmutable::now()->getTimestamp(),
        'chat' => ['id' => $fromId, 'type' => 'private'],
        'from' => ['id' => $fromId, 'first_name' => 'Alice', 'language_code' => 'ru'],
        'refunded_payment' => ['currency' => 'XTR', 'total_amount' => $amount, 'invoice_payload' => 'whatever', 'telegram_payment_charge_id' => $chargeId],
    ]];
}

// --- successful_payment ------------------------------------------------------------------------

it('credits the first payment of a subscription: Pro until subscription_expiration_date, one ledger row, one reply with the app button', function (): void {
    $order = starsOrder($this->learner);
    $expiration = CarbonImmutable::now()->addDays(30)->addHours(2);
    $this->telegram->sent = [];

    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id, expiration: $expiration->getTimestamp()));

    $payment = Payment::query()->sole();
    $subscription = Subscription::query()->sole();
    expect($payment->status)->toBe('succeeded')
        ->and($payment->kind)->toBe('charge')
        ->and($payment->provider_event_id)->toBe('ch_first')
        ->and($payment->provider_charge_id)->toBe('ch_first')
        ->and($payment->payment_order_id)->toBe($order->id)
        ->and($payment->raw['provider_payment_charge_id'])->toBe('prov_ch_first')
        ->and($payment->raw)->not->toHaveKey('invoice_payload')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->provider)->toBe('telegram_stars')
        ->and($subscription->meta['stars']['charge_id'])->toBe('ch_first')
        ->and($subscription->current_period_ends_at?->toIso8601String())->toBe($expiration->toIso8601String())
        ->and($order->refresh()->status)->toBe('paid')
        ->and(app(EntitlementResolver::class)->for($this->learner)->plan)->toBe(Plan::Pro);

    // One message: the payment confirmation with the web_app button (the DM catch-all stayed silent).
    expect($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['chat_id'])->toBe($this->learner->telegram_user_id)
        ->and($this->telegram->sent[0]['text'])->toBe('Оплата получена! LexiFlow Pro активен до '.$expiration->setTimezone('Asia/Tashkent')->format('d.m.Y').'.')
        ->and($this->telegram->sent[0]['button'])->toBe('Открыть LexiFlow')
        ->and($this->telegram->sent[0]['url'])->toBe('https://lexiflow.test/twa/?from=payment');
});

it('applies a re-delivered successful_payment once: one payment, one subscription period, no second message', function (): void {
    $order = starsOrder($this->learner);
    $update = starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id);
    $dispatcher = app(TelegramDispatcher::class);

    $dispatcher->dispatch($update);
    $ends = Subscription::query()->sole()->current_period_ends_at;
    $sentAfterFirst = count($this->telegram->sent);

    $dispatcher->dispatch($update);
    (new HandleTelegramUpdate($update))->handle($dispatcher);

    expect(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->current_period_ends_at?->toIso8601String())->toBe($ends?->toIso8601String())
        ->and(count(Subscription::query()->sole()->meta['history']))->toBe(1)
        ->and($this->telegram->sent)->toHaveCount($sentAfterFirst)
        ->and(ProductEvent::query()->where('name', 'payment.succeeded')->count())->toBe(1);
});

it('extends the subscription on a recurring charge to the new subscription_expiration_date and re-activates it from past_due or canceled', function (): void {
    $order = starsOrder($this->learner);
    $dispatcher = app(TelegramDispatcher::class);
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $subscription = Subscription::query()->sole();

    // 30 days later Telegram charged again; our row sits in grace.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30)->addHour());
    $subscription->update(['status' => 'past_due', 'grace_until' => CarbonImmutable::now()->addDays(2)]);
    $newEnd = CarbonImmutable::now()->addDays(30)->subHour();
    $this->telegram->sent = [];

    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_second', $this->learner->telegram_user_id, first: false, expiration: $newEnd->getTimestamp(), updateId: 2));

    $renewal = Payment::query()->where('provider_event_id', 'ch_second')->sole();
    expect($renewal->kind)->toBe('renewal')
        ->and($renewal->status)->toBe('succeeded')
        ->and($renewal->payment_order_id)->toBe($order->id)
        ->and($subscription->refresh()->status)->toBe('active')
        ->and($subscription->current_period_ends_at?->toIso8601String())->toBe($newEnd->toIso8601String())
        ->and($subscription->grace_until)->toBeNull()
        ->and($subscription->provider_ref)->toBe('ch_second')
        ->and($subscription->meta['stars']['charge_id'])->toBe('ch_first')
        ->and($order->refresh()->status)->toBe('paid')
        ->and($this->telegram->sent[0]['text'])->toStartWith('Подписка продлена: LexiFlow Pro активен до ');

    // A renewal after the learner canceled (Telegram still charged, e.g. the cancel came too late) re-activates as well.
    $subscription->update(['status' => 'canceled', 'canceled_at' => CarbonImmutable::now()]);
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_third', $this->learner->telegram_user_id, first: false, expiration: $newEnd->addDays(30)->getTimestamp(), updateId: 3));
    expect($subscription->refresh()->status)->toBe('active')->and($subscription->canceled_at)->toBeNull()
        ->and(Payment::query()->count())->toBe(3);
});

it('credits a one-off yearly invoice for 365 days from the order period', function (): void {
    $order = starsOrder($this->learner, 'year');

    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_year', $this->learner->telegram_user_id, subscription: false));

    $subscription = Subscription::query()->sole();
    expect(Payment::query()->sole()->kind)->toBe('charge')
        ->and($subscription->current_period_ends_at?->toIso8601String())->toBe(CarbonImmutable::now()->addDays(365)->toIso8601String())
        ->and($subscription->provider)->toBe('telegram_stars')
        ->and($this->telegram->sent[0]['text'])->toContain(CarbonImmutable::now()->addDays(365)->setTimezone('Asia/Tashkent')->format('d.m.Y'));
});

it('records money for an unknown order as an orphan failed row for the payer and tells them support is on it', function (): void {
    Log::spy();
    $order = starsOrder($this->learner);
    $ghost = PaymentOrder::factory()->make(['token' => 'gone-gone-gone-gone-gone-gone-12', 'amount' => $order->amount]);

    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($ghost, 'ch_lost', $this->learner->telegram_user_id, first: false));

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe('failed')
        ->and($payment->kind)->toBe('renewal')
        ->and($payment->payment_order_id)->toBeNull()
        ->and($payment->subscriber_id)->toBe($this->learner->id)
        ->and($payment->raw['failure_reason'])->toBe('order_not_found')
        ->and(Subscription::query()->count())->toBe(0)
        ->and($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['text'])->toContain('/paysupport');
    Log::shouldHaveReceived('error')->withArgs(fn (string $m): bool => $m === 'billing.payment_orphan')->once();

    // Re-delivery: still one row, no second message.
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($ghost, 'ch_lost', $this->learner->telegram_user_id, first: false));
    expect(Payment::query()->count())->toBe(1)->and($this->telegram->sent)->toHaveCount(1);

    // Unknown payer: nothing to attach the money to; only the alert.
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($ghost, 'ch_lost_2', 424242, first: false, updateId: 9));
    expect(Payment::query()->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx): bool => $m === 'billing.payment_orphan' && $ctx['reason'] === 'order_not_found_and_unknown_learner')->once();
});

it('keeps the subscription untouched on an expired order or a wrong amount: failed row, support text, no retry', function (): void {
    Log::spy();
    $order = starsOrder($this->learner);
    $dispatcher = app(TelegramDispatcher::class);

    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_short', $this->learner->telegram_user_id, amount: $order->amount - 1));
    expect(Payment::query()->where('provider_event_id', 'ch_short')->sole()->raw['failure_reason'])->toBe('amount_mismatch')
        ->and(Subscription::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe('pending')
        ->and($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['text'])->toContain('/paysupport');

    $order->update(['status' => 'expired']);
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_late', $this->learner->telegram_user_id, updateId: 2));
    expect(Payment::query()->where('provider_event_id', 'ch_late')->sole()->raw['failure_reason'])->toBe('order_expired')
        ->and(Subscription::query()->count())->toBe(0)
        ->and($this->telegram->sent)->toHaveCount(2)
        ->and(app(EntitlementResolver::class)->for($this->learner)->plan)->toBe(Plan::Free);
});

it('credits a payment even when the payer is over the inbound flood limit', function (): void {
    $order = starsOrder($this->learner);
    for ($i = 0; $i < 40; $i++) {
        RateLimiter::attempt('bot-in:'.$this->learner->telegram_user_id, 30, static fn (): bool => true, 60);
    }
    $dispatcher = app(TelegramDispatcher::class);

    // A plain DM is dropped (no reply)...
    $dispatcher->dispatch(['update_id' => 7, 'message' => ['chat' => ['id' => $this->learner->telegram_user_id, 'type' => 'private'], 'from' => ['id' => $this->learner->telegram_user_id], 'text' => '/pro']]);
    expect($this->telegram->sent)->toBe([]);

    // ...the payment is not.
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    expect(Payment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('ignores a malformed successful_payment without crashing the job', function (): void {
    Log::spy();

    app(TelegramDispatcher::class)->dispatch(['update_id' => 3, 'message' => ['chat' => ['id' => 5, 'type' => 'private'], 'from' => ['id' => 5], 'successful_payment' => ['currency' => 'XTR']]]);

    expect(Payment::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $m): bool => $m === 'billing.stars.successful_payment_malformed')->once();
});

// --- refunded_payment ----------------------------------------------------------------------------

it('applies a refund reported by Telegram: refund row, charge refunded, subscription ended, one reply; a repeat is silent', function (): void {
    $order = starsOrder($this->learner);
    $dispatcher = app(TelegramDispatcher::class);
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $charge = Payment::query()->sole();
    $this->telegram->sent = [];

    $dispatcher->dispatch(starsRefundUpdate('ch_first', $this->learner->telegram_user_id, $charge->amount));

    $refund = Payment::query()->where('kind', 'refund')->sole();
    expect($refund->provider_event_id)->toBe('refund:ch_first')
        ->and($refund->refunded_payment_id)->toBe($charge->id)
        ->and($refund->amount)->toBe($charge->amount)
        ->and($charge->refresh()->status)->toBe('refunded')
        ->and(Subscription::query()->sole()->status)->toBe('expired')
        ->and(app(EntitlementResolver::class)->for($this->learner)->plan)->toBe(Plan::Free)
        ->and($this->telegram->sent)->toHaveCount(1)
        ->and($this->telegram->sent[0]['text'])->toBe('Возврат '.$charge->amount.' ⭐ выполнен. Если этим платежом был оплачен текущий период Pro, он завершён.')
        ->and(AuditLog::query()->where('action', 'payment.refunded')->count())->toBe(0);

    $dispatcher->dispatch(starsRefundUpdate('ch_first', $this->learner->telegram_user_id, $charge->amount));
    expect(Payment::query()->where('kind', 'refund')->count())->toBe(1)->and($this->telegram->sent)->toHaveCount(1);
});

it('acknowledges the Telegram echo of an admin refund silently and logs a refund of a charge it never saw', function (): void {
    Log::spy();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $order = starsOrder($this->learner);
    $dispatcher = app(TelegramDispatcher::class);
    $dispatcher->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $charge = Payment::query()->sole();
    app(PaymentRefundService::class)->refund($charge, $admin, 'ticket #1');
    $this->telegram->sent = [];

    $dispatcher->dispatch(starsRefundUpdate('ch_first', $this->learner->telegram_user_id, $charge->amount));
    expect(Payment::query()->where('kind', 'refund')->count())->toBe(1)
        ->and(Payment::query()->where('kind', 'refund')->sole()->provider_event_id)->toBe('admin-refund:'.$charge->id)
        ->and($this->telegram->sent)->toBe([]);

    $dispatcher->dispatch(starsRefundUpdate('ch_unknown', $this->learner->telegram_user_id, 150, updateId: 51));
    expect(Payment::query()->count())->toBe(2)->and($this->telegram->sent)->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx): bool => $m === 'billing.stars.refund_unknown_charge' && $ctx['charge_id'] === 'ch_unknown')->once();
});

// --- cancel / resume -----------------------------------------------------------------------------

it('cancels the auto-renewal: editUserStarSubscription outside any transaction before the row changes, then canceled stays live; resume reverses it', function (): void {
    $order = starsOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $subscription = Subscription::query()->sole();
    $observed = [];
    $baseline = DB::transactionLevel(); // RefreshDatabase wraps the test itself in a transaction
    $this->telegram->onStarsCall = function (string $method) use (&$observed, $subscription, $baseline): void {
        $observed[] = [$method, DB::transactionLevel() - $baseline, Subscription::query()->whereKey($subscription->id)->value('status')];
    };
    $billing = app(LearnerBillingService::class);

    $canceled = $billing->cancelAutoRenew($this->learner, '10.0.0.1');

    expect($canceled->status)->toBe('canceled')
        ->and($canceled->canceled_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and($canceled->current_period_ends_at?->toIso8601String())->toBe($subscription->current_period_ends_at?->toIso8601String())
        ->and($this->telegram->starSubscriptionEdits)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => true]])
        // The provider was called with no open transaction while the row was still `active`.
        ->and($observed)->toBe([['editUserStarSubscription', 0, 'active']])
        ->and(app(EntitlementResolver::class)->for($this->learner)->plan)->toBe(Plan::Pro)
        ->and(app(EntitlementResolver::class)->for($this->learner)->until?->toIso8601String())->toBe($subscription->current_period_ends_at?->toIso8601String()); // canceled: no grace

    $resumed = $billing->resumeAutoRenew($this->learner, '10.0.0.1');

    expect($resumed->status)->toBe('active')
        ->and($resumed->canceled_at)->toBeNull()
        ->and($this->telegram->starSubscriptionEdits[1])->toBe(['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first', 'is_canceled' => false])
        ->and($observed[1])->toBe(['editUserStarSubscription', 0, 'canceled']);
});

it('leaves the subscription active when Telegram refuses the cancel', function (): void {
    $order = starsOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $this->telegram->failStars = true;

    expect(fn () => app(LearnerBillingService::class)->cancelAutoRenew($this->learner))->toThrow(ProviderUnavailableException::class);
    expect(Subscription::query()->sole()->status)->toBe('active');
});

// --- admin refund through the real provider --------------------------------------------------------

it('refunds from the admin panel through refundStarPayment, called outside any transaction before the ledger changes', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $order = starsOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $charge = Payment::query()->sole();
    $observed = [];
    $baseline = DB::transactionLevel();
    $this->telegram->onStarsCall = function (string $method) use (&$observed, $baseline): void {
        $observed[] = [$method, DB::transactionLevel() - $baseline, Payment::query()->where('kind', 'refund')->count(), Payment::query()->where('kind', 'charge')->value('status')];
    };

    $this->actingAs($admin);
    Livewire::test(ListPayments::class)
        ->callTableAction('refund', $charge, ['note' => 'Customer request'])
        ->assertHasNoTableActionErrors();

    expect($this->telegram->starRefunds)->toBe([['user_id' => $this->learner->telegram_user_id, 'charge_id' => 'ch_first']])
        ->and($observed)->toBe([['refundStarPayment', 0, 0, 'succeeded']])
        ->and($charge->refresh()->status)->toBe('refunded')
        ->and(Payment::query()->where('kind', 'refund')->sole()->provider_event_id)->toBe('admin-refund:'.$charge->id)
        ->and(Subscription::query()->sole()->status)->toBe('expired')
        ->and(AuditLog::query()->where('action', 'payment.refunded')->sole()->meta['note'])->toBe('Customer request');
});

it('does not touch the ledger when Telegram refuses the admin refund', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $order = starsOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $charge = Payment::query()->sole();
    $this->telegram->failStars = true;

    expect(fn () => app(PaymentRefundService::class)->refund($charge, $admin, 'x'))->toThrow(PaymentRefundException::class);
    expect($charge->refresh()->status)->toBe('succeeded')
        ->and(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->status)->toBe('active');
});

// --- billing:reconcile-stars -------------------------------------------------------------------

it('reconciles Telegram Stars transactions against the ledger: alerts on a missing charge and a missing refund, ignores other partners and old rows', function (): void {
    Log::spy();
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $order = starsOrder($this->learner);
    app(TelegramDispatcher::class)->dispatch(starsPaidUpdate($order, 'ch_first', $this->learner->telegram_user_id));
    $charge = Payment::query()->sole();
    app(PaymentRefundService::class)->refund($charge, $admin, 'ticket');
    $now = CarbonImmutable::now()->getTimestamp();
    $other = Learner::factory()->create();

    $this->telegram->starTransactions = [
        ['id' => 'tx_refund_ok', 'amount' => $charge->amount, 'date' => $now - 60, 'receiver' => ['type' => 'user', 'user' => ['id' => $this->learner->telegram_user_id]]],
        ['id' => 'tx_refund_missing', 'amount' => 150, 'date' => $now - 120, 'receiver' => ['type' => 'user', 'user' => ['id' => $other->telegram_user_id]]],
        ['id' => 'ch_first', 'amount' => $charge->amount, 'date' => $now - 300, 'source' => ['type' => 'user', 'user' => ['id' => $this->learner->telegram_user_id], 'invoice_payload' => $order->token]],
        ['id' => 'ch_missing', 'amount' => 150, 'date' => $now - 400, 'source' => ['type' => 'user', 'user' => ['id' => $other->telegram_user_id], 'invoice_payload' => 'x']],
        ['id' => 'tx_withdrawal', 'amount' => 1000, 'date' => $now - 500, 'receiver' => ['type' => 'fragment']],
        ['id' => 'ch_ancient', 'amount' => 150, 'date' => $now - 10 * 86_400, 'source' => ['type' => 'user', 'user' => ['id' => $other->telegram_user_id]]],
    ];

    $this->artisan('billing:reconcile-stars', ['--days' => 2])
        ->expectsOutputToContain('5 transactions checked over 1 page(s); 1 charge(s) and 1 refund(s) missing')
        ->assertExitCode(1);

    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx): bool => $m === 'billing.reconcile_missing' && $ctx['transaction_id'] === 'ch_missing')->once();
    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $ctx): bool => $m === 'billing.reconcile_missing_refund' && $ctx['transaction_id'] === 'tx_refund_missing')->once();
    Log::shouldNotHaveReceived('error', fn (string $m, array $ctx): bool => ($ctx['transaction_id'] ?? null) === 'ch_first' || ($ctx['transaction_id'] ?? null) === 'tx_refund_ok');
    expect($this->telegram->starTransactionPages)->toBe([['offset' => 0, 'limit' => 100]])
        ->and(Payment::query()->count())->toBe(2); // read-only
});

it('pages through getStarTransactions until the cut-off, exits 0 when everything matches and does nothing while Stars is off', function (): void {
    $now = CarbonImmutable::now()->getTimestamp();
    $page = [];
    for ($i = 0; $i < 100; $i++) {
        $page[] = ['id' => "w{$i}", 'amount' => 1, 'date' => $now - $i, 'receiver' => ['type' => 'fragment']];
    }
    $this->telegram->starTransactions = [...$page, ['id' => 'old', 'amount' => 1, 'date' => $now - 5 * 86_400, 'receiver' => ['type' => 'fragment']]];

    $this->artisan('billing:reconcile-stars')->expectsOutputToContain('100 transactions checked over 2 page(s)')->assertExitCode(0);
    expect($this->telegram->starTransactionPages)->toBe([['offset' => 0, 'limit' => 100], ['offset' => 100, 'limit' => 100]]);

    config()->set('billing.providers.telegram_stars.enabled', false);
    $this->telegram->starTransactionPages = [];
    $this->artisan('billing:reconcile-stars')->expectsOutputToContain('disabled')->assertExitCode(0);
    expect($this->telegram->starTransactionPages)->toBe([]);

    config()->set('billing.providers.telegram_stars.enabled', true);
    $this->telegram->failStars = true;
    $this->artisan('billing:reconcile-stars')->assertExitCode(1);
});
