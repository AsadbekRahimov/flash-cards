<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Providers\Payme\PaymeRpcException;
use App\Domain\Billing\Providers\Payme\PaymeRpcService;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymeFixtures;

/*
 * PaymeRpcService (docs/13 §4.2): the six Merchant API methods on the fixtures of
 * tests/Fixtures/billing/payme and the state matrix of `provider_transactions`
 * (create -> perform -> check; create -> timeout; perform twice; cancel before / after perform;
 * wrong amount; dead order; busy order). Wire-level concerns (Basic auth, JSON, HTTP) are in
 * PaymeWebhookTest; real races in BillingConcurrencyTest.
 */

uses(RefreshDatabase::class);

const PAYME_NOW = '2026-10-19 12:00:00';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse(PAYME_NOW, 'UTC'));
    config()->set('billing.providers.payme.enabled', true);
    config()->set('billing.providers.payme.fiscal.ikpu', '');
    config()->set('billing.providers.payme.cancel_after_perform_days', null);
    $this->rpc = app(PaymeRpcService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> the full JSON-RPC response */
function paymeCall(string $method, PaymentOrder $order, array $params = [], string $transactionId = PaymeFixtures::TRANSACTION_ID): array
{
    return app(PaymeRpcService::class)->handle(PaymeFixtures::for($method, $order, $params, $transactionId));
}

/** @return array<string, mixed> the `result` member; fails the test on an error */
function paymeResult(string $method, PaymentOrder $order, array $params = [], string $transactionId = PaymeFixtures::TRANSACTION_ID): array
{
    $response = paymeCall($method, $order, $params, $transactionId);
    expect(array_key_exists('result', $response))->toBeTrue('Expected a result, got '.json_encode($response));

    return $response['result'];
}

/** Asserts a JSON-RPC error with the given Payme code (and `data` when expected) and returns the error member. */
function paymeError(array $response, int $code, ?string $data = null): array
{
    expect(array_key_exists('error', $response))->toBeTrue('Expected an error, got '.json_encode($response))
        ->and($response['error']['code'])->toBe($code)
        ->and($response['error']['message'])->toHaveKeys(['ru', 'uz', 'en'])
        ->and($response)->not->toHaveKey('result');

    if ($data !== null) {
        expect($response['error']['data'])->toBe($data);
    } else {
        expect($response['error'])->not->toHaveKey('data');
    }

    return $response['error'];
}

function paymeTransaction(string $id = PaymeFixtures::TRANSACTION_ID): ProviderTransaction
{
    return ProviderTransaction::query()->where('provider', 'payme')->where('provider_transaction_id', $id)->firstOrFail();
}

// --- CheckPerformTransaction -----------------------------------------------------------------

it('allows a pending Payme order with the right amount and adds the fiscal detail only when an IKPU is configured', function (): void {
    $order = PaymentOrder::factory()->payme()->create(['meta' => ['learner_locale' => 'uz']]);

    expect(paymeResult('CheckPerformTransaction', $order))->toBe(['allow' => true]);

    config()->set('billing.providers.payme.fiscal', ['ikpu' => '10899002001000000', 'package_code' => '1209876', 'vat_percent' => 12]);
    $result = paymeResult('CheckPerformTransaction', $order);

    expect($result['allow'])->toBeTrue()
        ->and($result['detail'])->toBe([
            'receipt_type' => 0,
            'items' => [[
                'title' => 'LexiFlow Pro — 1 oy',
                'price' => $order->amount,
                'count' => 1,
                'code' => '10899002001000000',
                'package_code' => '1209876',
                'vat_percent' => 12,
            ]],
        ]);
});

it('refuses CheckPerformTransaction for an unknown, paid, expired, canceled or non-Payme order, a wrong amount and a busy order', function (): void {
    $order = PaymentOrder::factory()->payme()->create();

    paymeError(paymeCall('CheckPerformTransaction', $order, ['account' => ['order_id' => 'no-such-token']]), PaymeRpcException::ORDER_NOT_FOUND, 'order_id');
    paymeError(paymeCall('CheckPerformTransaction', $order, ['account' => ['order_id' => '']]), PaymeRpcException::ORDER_NOT_FOUND, 'order_id');
    paymeError(app(PaymeRpcService::class)->handle(PaymeFixtures::rpc('CheckPerformTransaction', ['account' => []])), PaymeRpcException::ORDER_NOT_FOUND, 'order_id');

    paymeError(paymeCall('CheckPerformTransaction', $order, ['amount' => $order->amount + 100]), PaymeRpcException::WRONG_AMOUNT);
    paymeError(paymeCall('CheckPerformTransaction', $order, ['amount' => '29000.00']), PaymeRpcException::WRONG_AMOUNT);
    paymeError(paymeCall('CheckPerformTransaction', $order, ['amount' => null]), PaymeRpcException::WRONG_AMOUNT);

    $paid = PaymentOrder::factory()->payme()->paid()->create();
    paymeError(paymeCall('CheckPerformTransaction', $paid), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    $expired = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subSecond()]);
    paymeError(paymeCall('CheckPerformTransaction', $expired), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    $canceled = PaymentOrder::factory()->payme()->create(['status' => PaymentOrder::STATUS_CANCELED]);
    paymeError(paymeCall('CheckPerformTransaction', $canceled), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    $click = PaymentOrder::factory()->click()->create();
    paymeError(paymeCall('CheckPerformTransaction', $click), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    // Another Payme transaction already waits for payment on this order.
    paymeResult('CreateTransaction', $order, [], 'other-open-tx');
    paymeError(paymeCall('CheckPerformTransaction', $order), PaymeRpcException::ORDER_BUSY, 'order_id');
});

// --- CreateTransaction -----------------------------------------------------------------------

it('creates a transaction once and answers a repeated CreateTransaction with the same id identically', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    $time = CarbonImmutable::now()->subSeconds(30)->getTimestampMs();

    $first = paymeResult('CreateTransaction', $order, ['time' => $time]);
    $tx = paymeTransaction();

    expect($first)->toBe(['create_time' => $time, 'transaction' => (string) $tx->id, 'state' => 1])
        ->and($tx->payment_order_id)->toBe($order->id)
        ->and($tx->amount)->toBe($order->amount)
        ->and($tx->provider_time)->toBe($time)
        ->and($tx->raw)->toBe(['method' => 'CreateTransaction', 'params' => ['id' => PaymeFixtures::TRANSACTION_ID, 'time' => $time, 'amount' => $order->amount, 'account' => ['order_id' => $order->token]]])
        ->and($tx->history)->toHaveCount(1);

    // Same id again (Payme retried): the same answer, still one row, no new history entry.
    expect(paymeResult('CreateTransaction', $order, ['time' => $time]))->toBe($first)
        ->and(ProviderTransaction::query()->count())->toBe(1)
        ->and(paymeTransaction()->history)->toHaveCount(1)
        ->and($order->refresh()->status)->toBe('pending');

    expect(paymeResult('CheckTransaction', $order))->toBe([
        'create_time' => $time, 'perform_time' => 0, 'cancel_time' => 0, 'transaction' => (string) $tx->id, 'state' => 1, 'reason' => null,
    ]);
});

it('refuses a second CreateTransaction with another id while the first one is open (-31052), and the usual order checks', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    paymeResult('CreateTransaction', $order);

    $busy = paymeError(paymeCall('CreateTransaction', $order, [], 'second-tx-id'), PaymeRpcException::ORDER_BUSY, 'order_id');
    expect($busy['message']['ru'])->toContain('ожидающая оплаты')
        ->and(ProviderTransaction::query()->count())->toBe(1);

    paymeError(paymeCall('CreateTransaction', $order, ['account' => ['order_id' => 'no-such-token']], 'tx-x'), PaymeRpcException::ORDER_NOT_FOUND, 'order_id');
    paymeError(paymeCall('CreateTransaction', $order, ['amount' => $order->amount - 1], 'tx-y'), PaymeRpcException::WRONG_AMOUNT);

    $paid = PaymentOrder::factory()->payme()->paid()->create();
    paymeError(paymeCall('CreateTransaction', $paid, [], 'tx-z'), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    $stale = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subMinute()]);
    paymeError(paymeCall('CreateTransaction', $stale, [], 'tx-w'), PaymeRpcException::ORDER_NOT_PAYABLE, 'order_id');

    expect(ProviderTransaction::query()->count())->toBe(1);
});

it('refuses a CreateTransaction whose time is older than the transaction timeout without storing anything', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    $tooOld = CarbonImmutable::now()->subMilliseconds(43_200_000 + 1)->getTimestampMs();

    paymeError(paymeCall('CreateTransaction', $order, ['time' => $tooOld]), PaymeRpcException::CANNOT_PERFORM);
    expect(ProviderTransaction::query()->count())->toBe(0);

    // Exactly at the limit is still fine.
    paymeResult('CreateTransaction', $order, ['time' => CarbonImmutable::now()->subMilliseconds(43_200_000)->getTimestampMs()]);
    expect(ProviderTransaction::query()->count())->toBe(1);
});

// --- PerformTransaction ----------------------------------------------------------------------

it('performs once: ledger row, order paid, subscription for the period; a repeat returns the same perform_time', function (): void {
    $learner = Learner::factory()->create();
    $order = PaymentOrder::factory()->payme()->create(['subscriber_id' => $learner->id]);
    paymeResult('CreateTransaction', $order);
    $tx = paymeTransaction();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(20));
    $performedAt = CarbonImmutable::now();
    $first = paymeResult('PerformTransaction', $order);

    expect($first)->toBe(['transaction' => (string) $tx->id, 'perform_time' => $performedAt->getTimestampMs(), 'state' => 2]);

    $tx->refresh();
    expect($tx->state)->toBe(2)
        ->and($tx->perform_time)->toBe($performedAt->getTimestampMs())
        ->and($tx->performed_at?->getTimestamp())->toBe($performedAt->getTimestamp())
        ->and($tx->raw['method'])->toBe('PerformTransaction')
        ->and(array_column($tx->history, 'method'))->toBe(['create', 'PerformTransaction']);

    $payment = Payment::query()->sole();
    expect($payment->provider)->toBe('payme')
        ->and($payment->provider_event_id)->toBe(PaymeFixtures::TRANSACTION_ID.':perform')
        ->and($payment->provider_charge_id)->toBe(PaymeFixtures::TRANSACTION_ID)
        ->and($payment->provider_transaction_id)->toBe($tx->id)
        ->and($payment->status)->toBe('succeeded')
        ->and($payment->kind)->toBe('charge')
        ->and($payment->amount)->toBe($order->amount)
        ->and($payment->currency)->toBe('UZS')
        ->and($payment->raw)->not->toHaveKey('order_id');

    $order->refresh();
    $subscription = Subscription::query()->sole();
    expect($order->status)->toBe('paid')
        ->and($order->subscription_id)->toBe($subscription->id)
        ->and($subscription->provider)->toBe('payme')
        ->and($subscription->status)->toBe(SubscriptionStatus::Active->value)
        ->and($subscription->subscriber_id)->toBe($learner->id)
        ->and($subscription->current_period_ends_at?->toIso8601String())->toBe($performedAt->addDays(30)->toIso8601String());

    // Payme repeats PerformTransaction a minute later: the stored answer, no second charge.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    expect(paymeResult('PerformTransaction', $order))->toBe($first)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(paymeTransaction()->history)->toHaveCount(2);

    expect(paymeResult('CheckTransaction', $order))->toMatchArray(['perform_time' => $performedAt->getTimestampMs(), 'cancel_time' => 0, 'state' => 2, 'reason' => null]);
});

it('refuses PerformTransaction for an unknown id and for a canceled transaction', function (): void {
    $order = PaymentOrder::factory()->payme()->create();

    paymeError(paymeCall('PerformTransaction', $order, [], 'never-created'), PaymeRpcException::TRANSACTION_NOT_FOUND);

    paymeResult('CreateTransaction', $order);
    paymeResult('CancelTransaction', $order, ['reason' => 3]);
    paymeError(paymeCall('PerformTransaction', $order), PaymeRpcException::CANNOT_PERFORM);

    expect(Payment::query()->count())->toBe(0)->and(paymeTransaction()->state)->toBe(-1);
});

it('cancels a timed-out open transaction with reason 4 and refuses to perform or re-create it; the order becomes payable again', function (): void {
    $order = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->addDays(2)]);
    $time = CarbonImmutable::now()->getTimestampMs();
    $created = paymeResult('CreateTransaction', $order, ['time' => $time]);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds(43_200_000 + 1_000));
    $cancelMs = CarbonImmutable::now()->getTimestampMs();

    paymeError(paymeCall('PerformTransaction', $order), PaymeRpcException::CANNOT_PERFORM);

    $tx = paymeTransaction();
    expect($tx->state)->toBe(-1)
        ->and($tx->cancel_reason)->toBe(4)
        ->and($tx->cancel_time)->toBe($cancelMs)
        ->and($tx->canceled_at)->not->toBeNull()
        ->and(array_column($tx->history, 'method'))->toBe(['create', 'PerformTransaction'])
        ->and(Payment::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe('pending');

    expect(paymeResult('CheckTransaction', $order))->toBe([
        'create_time' => $time, 'perform_time' => 0, 'cancel_time' => $cancelMs, 'transaction' => $created['transaction'], 'state' => -1, 'reason' => 4,
    ]);

    // The same id cannot be re-created; a fresh one on the same order can.
    paymeError(paymeCall('CreateTransaction', $order, ['time' => $time]), PaymeRpcException::CANNOT_PERFORM);
    expect(paymeResult('CheckPerformTransaction', $order))->toBe(['allow' => true]);
    expect(paymeResult('CreateTransaction', $order, [], 'fresh-tx')['state'])->toBe(1);
});

it('cancels a timed-out open transaction on the next CreateTransaction with the same id (-31008, state -1, reason 4)', function (): void {
    $order = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->addDays(2)]);
    $time = CarbonImmutable::now()->subHours(13)->getTimestampMs();
    ProviderTransaction::factory()->create(['payment_order_id' => $order->id, 'provider_transaction_id' => PaymeFixtures::TRANSACTION_ID, 'amount' => $order->amount, 'provider_time' => $time, 'created_at_provider' => CarbonImmutable::createFromTimestampMs($time)]);

    paymeError(paymeCall('CreateTransaction', $order, ['time' => $time]), PaymeRpcException::CANNOT_PERFORM);

    $tx = paymeTransaction();
    expect($tx->state)->toBe(-1)->and($tx->cancel_reason)->toBe(4)->and($tx->cancel_time)->toBe(CarbonImmutable::now()->getTimestampMs());

    // A stale open transaction never blocks the order: CheckPerformTransaction closes it and allows.
    $order2 = PaymentOrder::factory()->payme()->create();
    ProviderTransaction::factory()->create(['payment_order_id' => $order2->id, 'provider_transaction_id' => 'stale-on-order-2', 'amount' => $order2->amount, 'provider_time' => $time, 'created_at_provider' => CarbonImmutable::createFromTimestampMs($time)]);
    expect(paymeResult('CheckPerformTransaction', $order2))->toBe(['allow' => true])
        ->and(paymeTransaction('stale-on-order-2')->cancel_reason)->toBe(4);
});

it('refuses to perform when the order died after CreateTransaction, keeps the transaction open and answers retries the same way', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    paymeResult('CreateTransaction', $order);
    $order->update(['status' => PaymentOrder::STATUS_CANCELED]); // an operator closed the order meanwhile

    paymeError(paymeCall('PerformTransaction', $order), PaymeRpcException::CANNOT_PERFORM);
    paymeError(paymeCall('PerformTransaction', $order), PaymeRpcException::CANNOT_PERFORM);

    expect(paymeTransaction()->state)->toBe(1)
        ->and(Payment::query()->where('status', 'failed')->count())->toBe(1)
        ->and(Payment::query()->where('status', 'succeeded')->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    // Payme gives up and cancels: state -1, nothing to refund.
    expect(paymeResult('CancelTransaction', $order, ['reason' => 3])['state'])->toBe(-1);
});

// --- CancelTransaction -----------------------------------------------------------------------

it('cancels before perform: state -1 with the reason, the order stays pending, the repeat is identical, a new transaction may follow', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    $created = paymeResult('CreateTransaction', $order);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $cancelMs = CarbonImmutable::now()->getTimestampMs();
    $first = paymeResult('CancelTransaction', $order, ['reason' => 2]);

    expect($first)->toBe(['transaction' => $created['transaction'], 'cancel_time' => $cancelMs, 'state' => -1]);

    $tx = paymeTransaction();
    expect($tx->cancel_reason)->toBe(2)
        ->and($tx->raw['method'])->toBe('CancelTransaction')
        ->and(array_column($tx->history, 'state_to'))->toBe([1, -1])
        ->and($order->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
    expect(paymeResult('CancelTransaction', $order, ['reason' => 2]))->toBe($first)
        ->and(paymeTransaction()->history)->toHaveCount(2);

    // The payer retries on the same checkout page: Payme opens a new transaction on the same account.
    expect(paymeResult('CreateTransaction', $order, [], 'retry-tx')['state'])->toBe(1);
});

it('cancels after perform: refund row, charge refunded, subscription ended now, state -2; the repeat is identical', function (): void {
    $learner = Learner::factory()->create();
    $order = PaymentOrder::factory()->payme()->create(['subscriber_id' => $learner->id]);
    paymeResult('CreateTransaction', $order);
    paymeResult('PerformTransaction', $order);
    $created = paymeTransaction();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(3));
    $cancelMs = CarbonImmutable::now()->getTimestampMs();
    $first = paymeResult('CancelTransaction', $order, ['reason' => 5]);

    expect($first)->toBe(['transaction' => (string) $created->id, 'cancel_time' => $cancelMs, 'state' => -2]);

    $tx = paymeTransaction();
    expect($tx->state)->toBe(-2)->and($tx->cancel_reason)->toBe(5)->and($tx->perform_time)->toBe($created->perform_time);

    $charge = Payment::query()->where('kind', 'charge')->sole();
    $refund = Payment::query()->where('kind', 'refund')->sole();
    expect($charge->status)->toBe('refunded')
        ->and($refund->provider_event_id)->toBe(PaymeFixtures::TRANSACTION_ID.':cancel')
        ->and($refund->refunded_payment_id)->toBe($charge->id)
        ->and($refund->amount)->toBe($order->amount)
        ->and($refund->raw['reason'])->toBe(5);

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired->value)
        ->and($subscription->ended_at?->getTimestamp())->toBe(CarbonImmutable::now()->getTimestamp())
        ->and($order->refresh()->status)->toBe('paid');

    expect(paymeResult('CancelTransaction', $order, ['reason' => 5]))->toBe($first)
        ->and(Payment::query()->count())->toBe(2);

    expect(paymeResult('CheckTransaction', $order))->toMatchArray(['perform_time' => $created->perform_time, 'cancel_time' => $cancelMs, 'state' => -2, 'reason' => 5]);
    paymeError(paymeCall('PerformTransaction', $order), PaymeRpcException::CANNOT_PERFORM);
});

it('refuses CancelTransaction after perform outside the configured refund window (-31007) and for an unknown id', function (): void {
    config()->set('billing.providers.payme.cancel_after_perform_days', 7);
    $order = PaymentOrder::factory()->payme()->create();
    paymeResult('CreateTransaction', $order);
    paymeResult('PerformTransaction', $order);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(8));
    paymeError(paymeCall('CancelTransaction', $order, ['reason' => 5]), PaymeRpcException::CANNOT_CANCEL);
    expect(paymeTransaction()->state)->toBe(2)->and(Payment::query()->count())->toBe(1);

    paymeError(paymeCall('CancelTransaction', $order, ['reason' => 5], 'unknown-id'), PaymeRpcException::TRANSACTION_NOT_FOUND);
});

it('still records state -2 when the admin already marked the refund by hand', function (): void {
    $order = PaymentOrder::factory()->payme()->create();
    paymeResult('CreateTransaction', $order);
    paymeResult('PerformTransaction', $order);
    $charge = Payment::query()->sole();
    $charge->update(['status' => Payment::STATUS_REFUNDED]); // 2A.7 "returned by hand" leaves the charge refunded

    expect(paymeResult('CancelTransaction', $order, ['reason' => 5])['state'])->toBe(-2)
        ->and(Payment::query()->where('kind', 'refund')->count())->toBe(0)
        ->and(paymeTransaction()->state)->toBe(-2);
});

// --- Organizations ---------------------------------------------------------------------------

it('performs an organization order: tier and seats from the order, organization subscription', function (): void {
    $organization = Organization::factory()->create();
    $order = PaymentOrder::factory()->forOrganization($organization, 'center_s')->create(['amount' => 34_900_000, 'list_amount' => 34_900_000]);
    paymeResult('CreateTransaction', $order);
    paymeResult('PerformTransaction', $order);

    $subscription = Subscription::query()->sole();
    expect($subscription->subscriber_type)->toBe('organization')
        ->and($subscription->subscriber_id)->toBe($organization->id)
        ->and($subscription->tier)->toBe('center_s')
        ->and($subscription->seats_limit)->toBe(100)
        ->and(Payment::query()->sole()->subscriber_type)->toBe('organization');
});

// --- GetStatement ----------------------------------------------------------------------------

it('lists the Payme transactions created in [from, to] with their account, times, state and reason', function (): void {
    $base = CarbonImmutable::now()->subHours(6);
    $orderA = PaymentOrder::factory()->payme()->create();
    $orderB = PaymentOrder::factory()->payme()->create();
    $orderC = PaymentOrder::factory()->payme()->create();

    paymeResult('CreateTransaction', $orderA, ['time' => $base->getTimestampMs()], 'tx-a');
    paymeResult('PerformTransaction', $orderA, [], 'tx-a');
    paymeResult('CreateTransaction', $orderB, ['time' => $base->addHour()->getTimestampMs()], 'tx-b');
    paymeResult('CancelTransaction', $orderB, ['reason' => 3], 'tx-b');
    paymeResult('CreateTransaction', $orderC, ['time' => $base->addHours(5)->getTimestampMs()], 'tx-c'); // outside the window below
    ProviderTransaction::factory()->create(['provider' => 'click', 'provider_transaction_id' => 'click-1', 'payment_order_id' => PaymentOrder::factory()->click()->create()->id, 'provider_time' => $base->addMinutes(10)->getTimestampMs()]);

    $result = app(PaymeRpcService::class)->handle(PaymeFixtures::rpc('GetStatement', ['from' => $base->getTimestampMs(), 'to' => $base->addHours(2)->getTimestampMs()]))['result'];
    $nowMs = CarbonImmutable::now()->getTimestampMs();

    expect($result['transactions'])->toHaveCount(2)
        ->and($result['transactions'][0])->toBe([
            'id' => 'tx-a', 'time' => $base->getTimestampMs(), 'amount' => $orderA->amount, 'account' => ['order_id' => $orderA->token],
            'create_time' => $base->getTimestampMs(), 'perform_time' => $nowMs, 'cancel_time' => 0,
            'transaction' => (string) paymeTransaction('tx-a')->id, 'state' => 2, 'reason' => null, 'receivers' => null,
        ])
        ->and($result['transactions'][1])->toMatchArray(['id' => 'tx-b', 'account' => ['order_id' => $orderB->token], 'perform_time' => 0, 'cancel_time' => $nowMs, 'state' => -1, 'reason' => 3]);

    // Empty window and an inverted one.
    expect(app(PaymeRpcService::class)->handle(PaymeFixtures::rpc('GetStatement', ['from' => 1, 'to' => 2]))['result'])->toBe(['transactions' => []]);
    paymeError(app(PaymeRpcService::class)->handle(PaymeFixtures::rpc('GetStatement', ['from' => 10, 'to' => 1])), PaymeRpcException::INVALID_REQUEST);
});

// --- Protocol --------------------------------------------------------------------------------

it('answers protocol errors: unknown or missing method, malformed params, bad ids, and echoes the request id', function (): void {
    $rpc = app(PaymeRpcService::class);

    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'SetFiscalData', 'params' => []]), PaymeRpcException::METHOD_NOT_FOUND);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'ChangePassword', 'params' => ['password' => 'x']]), PaymeRpcException::METHOD_NOT_FOUND);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 8]), PaymeRpcException::INVALID_REQUEST);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'CheckTransaction', 'params' => 'oops']), PaymeRpcException::INVALID_REQUEST);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'CheckTransaction', 'params' => ['id' => '']]), PaymeRpcException::INVALID_REQUEST);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'CheckTransaction', 'params' => ['id' => str_repeat('a', 65)]]), PaymeRpcException::INVALID_REQUEST);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'CancelTransaction', 'params' => ['id' => 'x', 'reason' => 'soon']]), PaymeRpcException::INVALID_REQUEST);
    paymeError($rpc->handle(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'CreateTransaction', 'params' => ['id' => 'x', 'time' => 'now', 'amount' => 1, 'account' => ['order_id' => 'y']]]), PaymeRpcException::INVALID_REQUEST);

    $response = $rpc->handle(['jsonrpc' => '2.0', 'id' => 'req-42', 'method' => 'CheckTransaction', 'params' => ['id' => 'nope']]);
    expect($response)->toMatchArray(['jsonrpc' => '2.0', 'id' => 'req-42'])->and($response['error']['code'])->toBe(PaymeRpcException::TRANSACTION_NOT_FOUND);

    // A numeric-string amount from a lenient client still matches.
    $order = PaymentOrder::factory()->payme()->create();
    expect(paymeResult('CheckPerformTransaction', $order, ['amount' => (string) $order->amount]))->toBe(['allow' => true]);
});
