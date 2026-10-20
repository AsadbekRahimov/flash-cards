<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderTransactionException;
use App\Domain\Billing\Services\ProviderTransactionService;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function ptxFailure(callable $call): ProviderTransactionException
{
    try {
        $call();
    } catch (ProviderTransactionException $e) {
        return $e;
    }

    throw new RuntimeException('Expected ProviderTransactionException');
}

it('opens one transaction per order, idempotently per provider id, and refuses a second open one', function (): void {
    $service = app(ProviderTransactionService::class);
    $order = PaymentOrder::factory()->payme()->create();
    $time = CarbonImmutable::now()->subMinute()->getTimestampMs();

    $tx = $service->open(SubscriptionProvider::Payme, 'tx-1', $order->token, $order->amount, $time, ['method' => 'CreateTransaction']);

    expect($tx->state)->toBe(ProviderTransaction::STATE_CREATED)
        ->and($tx->isOpen())->toBeTrue()
        ->and($tx->payment_order_id)->toBe($order->id)
        ->and($tx->amount)->toBe($order->amount)
        ->and($tx->provider_time)->toBe($time)
        ->and($tx->created_at_provider?->getTimestampMs())->toBe($time)
        ->and($tx->raw)->toBe(['method' => 'CreateTransaction'])
        ->and($tx->history)->toBe([['method' => 'create', 'at' => CarbonImmutable::now()->toIso8601String(), 'state_from' => 0, 'state_to' => 1]]);

    // Same provider id again: the same row, untouched.
    $again = $service->open(SubscriptionProvider::Payme, 'tx-1', $order->token, $order->amount, $time, []);
    expect($again->id)->toBe($tx->id)->and(ProviderTransaction::query()->count())->toBe(1);

    // Another transaction on the same order while tx-1 is open -> order_busy (Payme -31052).
    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Payme, 'tx-2', $order->token, $order->amount, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::ORDER_BUSY)->and($e->httpStatus())->toBe(409)->and($e->getMessage())->toContain('tx-1');

    // Once tx-1 is closed (canceled), a new one may be opened.
    $tx->update(['state' => ProviderTransaction::STATE_CANCELED, 'canceled_at' => CarbonImmutable::now()]);
    expect($service->open(SubscriptionProvider::Payme, 'tx-2', $order->token, $order->amount, null, [])->state)->toBe(1);
});

it('refuses unknown, unpayable and mismatching orders', function (): void {
    $service = app(ProviderTransactionService::class);

    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Payme, 'tx', 'no-such-token', 100, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::ORDER_NOT_FOUND)->and($e->httpStatus())->toBe(404);

    $paid = PaymentOrder::factory()->payme()->paid()->create();
    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Payme, 'tx', $paid->token, $paid->amount, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::ORDER_NOT_PAYABLE)->and($e->getMessage())->toContain('paid');

    $stale = PaymentOrder::factory()->payme()->create(['expires_at' => CarbonImmutable::now()->subSecond()]);
    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Payme, 'tx', $stale->token, $stale->amount, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::ORDER_NOT_PAYABLE)->and($e->getMessage())->toContain('expired');

    $order = PaymentOrder::factory()->payme()->create();
    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Payme, 'tx', $order->token, $order->amount + 1, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::AMOUNT_MISMATCH);

    // A Click transaction on a Payme order.
    $e = ptxFailure(fn () => $service->open(SubscriptionProvider::Click, 'tx', $order->token, $order->amount, null, []));
    expect($e->errorCode)->toBe(ProviderTransactionException::ORDER_NOT_PAYABLE);

    expect(ProviderTransaction::query()->count())->toBe(0);
});
