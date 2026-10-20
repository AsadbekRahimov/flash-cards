<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderTransactionException;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a provider transaction on an order — the shared core of Payme `CreateTransaction` and
 * Click `Prepare` (docs/13 §4.2, §4.3). Lock order (docs/13 §3): `payment_orders` FOR UPDATE ->
 * `provider_transactions`. The order lock makes "no other open transaction on this order" an
 * authoritative check; `(provider, provider_transaction_id)` unique is the backstop and makes a
 * repeated call for the same provider id return the existing row unchanged (idempotent).
 */
final class ProviderTransactionService
{
    /**
     * @param  array<string, mixed>  $raw  the provider's request after secrets were redacted
     * @param  bool  $exclusive  refuse (`order_busy`) while another transaction of the order is open — Payme
     *                           (its transactions time out, docs/13 §4.2); Click passes false: a payer who
     *                           retries gets a new `click_trans_id` and Click never times a Prepare out,
     *                           so a stale open one must not hold the order (docs/13 §4.3, 2A.5)
     *
     * @throws ProviderTransactionException order_not_found | order_not_payable | amount_mismatch | order_busy
     */
    public function open(
        SubscriptionProvider $provider,
        string $providerTransactionId,
        string $orderToken,
        int $amount,
        ?int $providerTime,
        array $raw,
        ?CarbonImmutable $now = null,
        bool $exclusive = true,
    ): ProviderTransaction {
        $now ??= CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($provider, $providerTransactionId, $orderToken, $amount, $providerTime, $raw, $now, $exclusive): ProviderTransaction {
                /** @var PaymentOrder|null $order */
                $order = PaymentOrder::query()->where('token', $orderToken)->lockForUpdate()->first();

                if ($order === null) {
                    throw ProviderTransactionException::orderNotFound();
                }

                /** @var ProviderTransaction|null $existing */
                $existing = ProviderTransaction::query()
                    ->where('provider', $provider->value)
                    ->where('provider_transaction_id', $providerTransactionId)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                if (! $order->isPayableAt($now) || $order->provider !== $provider->value) {
                    throw ProviderTransactionException::orderNotPayable($order->isPending() ? 'expired' : $order->status);
                }

                if ($amount !== $order->amount) {
                    throw ProviderTransactionException::amountMismatch($order->amount, $amount);
                }

                if ($exclusive) {
                    /** @var ProviderTransaction|null $open */
                    $open = ProviderTransaction::query()
                        ->where('payment_order_id', $order->id)
                        ->where('state', ProviderTransaction::STATE_CREATED)
                        ->lockForUpdate()
                        ->first();

                    if ($open !== null) {
                        throw ProviderTransactionException::orderBusy($open->provider_transaction_id);
                    }
                }

                $transaction = new ProviderTransaction([
                    'provider' => $provider->value,
                    'provider_transaction_id' => $providerTransactionId,
                    'payment_order_id' => $order->id,
                    'state' => ProviderTransaction::STATE_CREATED,
                    'amount' => $amount,
                    'provider_time' => $providerTime,
                    'created_at_provider' => $providerTime === null ? $now : CarbonImmutable::createFromTimestampMs($providerTime),
                    'raw' => $raw,
                    'history' => [],
                ]);
                $transaction->pushHistory('create', 0, ProviderTransaction::STATE_CREATED, $now);
                $transaction->save();

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race on the unique index to a parallel delivery of the same provider id.
            /** @var ProviderTransaction $existing */
            $existing = ProviderTransaction::query()
                ->where('provider', $provider->value)
                ->where('provider_transaction_id', $providerTransactionId)
                ->firstOrFail();

            return $existing;
        }
    }
}
