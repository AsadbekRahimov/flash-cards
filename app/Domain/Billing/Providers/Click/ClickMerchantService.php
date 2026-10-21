<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Click;

use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\ProviderTransactionException;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\OrganizationOrderGuard;
use App\Domain\Billing\Services\PaymentApplier;
use App\Domain\Billing\Services\ProviderTransactionService;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Click Merchant API, Prepare / Complete (docs/13 §4.3): the state machine of `provider_transactions`
 * for `provider = click` (1 prepared, 2 completed, -1 canceled) and the answers
 * `{click_trans_id, merchant_trans_id, merchant_prepare_id | merchant_confirm_id, error, error_note}`.
 *
 * Prepare opens the row (`merchant_prepare_id` = our `provider_transactions.id`) — a repeat with the
 * same `click_trans_id` returns the same id. Complete credits the order through PaymentApplier in
 * ONE transaction in the lock order of docs/13 §3 (`payment_orders` FOR UPDATE -> the transaction
 * row FOR UPDATE -> subject -> subscription -> INSERT payments), so two deliveries serialize on the
 * order row and the second answers exactly like the first ({@see REPEATED_COMPLETE_ERROR}).
 *
 * Business refusals are ClickException -> HTTP 200 + a negative `error`; infrastructure failures
 * propagate and the controller answers HTTP 500 + -7 so that Click retries.
 *
 * Order statuses map to Click codes as: paid -> -4; expired / canceled / failed (or past
 * `expires_at`) -> -9 "transaction canceled" (the order was closed on our side, the payer needs a
 * new one); a token that matches nothing, or an order of another provider -> -5 (nothing is
 * revealed about other providers' orders). An organization order that may not be paid any more
 * (its tier is no longer the live one, or the organization is suspended — OrganizationOrderGuard,
 * 2B.8) is -9 as well: the order exists but is closed to payment on our side, the owner needs a
 * new one; -5 would deny its existence and -7 would make Click retry.
 */
final class ClickMerchantService
{
    public const METHOD_PREPARE = 'prepare';

    public const METHOD_COMPLETE = 'complete';

    /** `payments.provider_event_id` suffix of a completed transaction (docs/13 §1.2). */
    public const EVENT_COMPLETE = ':complete';

    /**
     * What a repeated Complete of an already completed transaction gets: 0 with the same
     * `merchant_confirm_id` (idempotent, docs/13 §4.3). If the Click sandbox expects -4 instead,
     * change this one constant to ClickException::ALREADY_PAID (docs/13 §15 p. 3).
     */
    public const REPEATED_COMPLETE_ERROR = ClickException::SUCCESS;

    public function __construct(
        private readonly ProviderTransactionService $transactions,
        private readonly PaymentApplier $applier,
        private readonly CheckoutService $checkout,
        private readonly ClickSignature $signature,
        private readonly OrganizationOrderGuard $organizations,
    ) {}

    /**
     * Prepare (`action = 0`): signature -> order by `merchant_trans_id` -> payable? (organization guard
     * included, re-checked under the order lock) -> amount -> open the transaction (state 1). Answer
     * `merchant_prepare_id` = `provider_transactions.id`.
     *
     * @param  array<array-key, mixed>  $input  the decoded form
     * @return array<string, mixed>
     */
    public function prepare(array $input, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        try {
            $request = ClickRequest::parse($input, ClickRequest::ACTION_PREPARE);
            $this->verify($request);
            $amount = self::tiyin($request);

            $order = $this->findOrder($request->merchantTransId);
            $this->assertPayable($order, $now);
            if ($amount !== $order->amount) {
                throw ClickException::incorrectAmount("expected {$order->amount} tiyin, got {$amount}");
            }

            try {
                $transaction = $this->transactions->open(
                    SubscriptionProvider::Click,
                    $request->clickTransId,
                    $order->token,
                    $amount,
                    null,
                    self::raw(self::METHOD_PREPARE, $request),
                    $now,
                    exclusive: false,
                    guard: $this->organizations->refusal(...),
                );
            } catch (ProviderTransactionException $e) {
                throw match ($e->errorCode) {
                    ProviderTransactionException::ORDER_NOT_FOUND => ClickException::orderNotFound('order vanished'),
                    ProviderTransactionException::AMOUNT_MISMATCH => ClickException::incorrectAmount($e->getMessage()),
                    ProviderTransactionException::ORDER_NOT_PAYABLE => $this->refusalFor($this->findOrder($request->merchantTransId), $now),
                    default => ClickException::updateFailed($e->errorCode),
                };
            }

            // A known `click_trans_id` is returned as is (idempotent Prepare) — but only for this very order.
            if ($transaction->payment_order_id !== $order->id) {
                throw ClickException::requestError('click_trans_id belongs to another order');
            }
            if ($transaction->isCanceled()) {
                throw ClickException::transactionCanceled("transaction {$transaction->id} is canceled");
            }

            return self::success($request, ClickRequest::ACTION_PREPARE, $transaction->id);
        } catch (ClickException $e) {
            $this->logRefusal(self::METHOD_PREPARE, $input, $e);

            return self::errorResponse($input, ClickRequest::ACTION_PREPARE, $e);
        }
    }

    /**
     * Complete (`action = 1`): signature (with `merchant_prepare_id`) -> the prepared transaction ->
     * `error < 0` from Click: state -1 of this transaction only (the order stays pending), answer -9; otherwise
     * PaymentApplier::applyCharge inside the same transaction -> state 2, answer
     * `merchant_confirm_id` = `payments.id`. Repeats answer from the stored state.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public function complete(array $input, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        try {
            $request = ClickRequest::parse($input, ClickRequest::ACTION_COMPLETE);
            $this->verify($request);
            $amount = self::tiyin($request);

            $probe = $this->find($request->clickTransId);
            if ($probe === null || (string) $probe->id !== $request->merchantPrepareId) {
                throw ClickException::transactionNotFound("click_trans_id {$request->clickTransId} / merchant_prepare_id {$request->merchantPrepareId}");
            }

            /** @var array{0: int|null, 1: ClickException|null} $outcome */
            $outcome = DB::transaction(function () use ($probe, $request, $amount, $now): array {
                [$order, $transaction] = $this->lockOrderAndTransaction($probe);

                if ($order->token !== $request->merchantTransId) {
                    return [null, ClickException::transactionNotFound('merchant_trans_id does not match the prepared order')];
                }

                if ($request->error < 0) {
                    return [null, $this->cancelFromClick($order, $transaction, $request, $now)];
                }

                if ($transaction->isCanceled()) {
                    return [null, ClickException::transactionCanceled("transaction {$transaction->id} is canceled")];
                }

                if ($amount !== $transaction->amount) {
                    return [null, ClickException::incorrectAmount("expected {$transaction->amount} tiyin, got {$amount}")];
                }

                if ($transaction->isPerformed()) {
                    // The repeated answer is decided by one constant; a negative code becomes the error body.
                    $repeat = new ClickException(self::REPEATED_COMPLETE_ERROR, "transaction {$transaction->id} is already completed");

                    return $repeat->clickCode < 0
                        ? [null, $repeat]
                        : [$this->confirmIdOf($transaction), null];
                }

                [$payment, $failure] = $this->applyCharge($order, $transaction, $now);
                if ($failure !== null || $payment === null) {
                    return [null, $failure ?? ClickException::updateFailed('no payment recorded')];
                }

                $transaction->fill([
                    'state' => ProviderTransaction::STATE_PERFORMED,
                    'performed_at' => $now,
                    'perform_time' => $now->getTimestampMs(),
                    'raw' => self::raw(self::METHOD_COMPLETE, $request),
                ]);
                $transaction->pushHistory(self::METHOD_COMPLETE, ProviderTransaction::STATE_CREATED, ProviderTransaction::STATE_PERFORMED, $now);
                $transaction->save();

                return [$payment->id, null];
            });

            [$confirmId, $error] = $outcome;
            if ($error !== null) {
                throw $error;
            }

            return self::success($request, ClickRequest::ACTION_COMPLETE, $confirmId);
        } catch (ClickException $e) {
            $this->logRefusal(self::METHOD_COMPLETE, $input, $e);

            return self::errorResponse($input, ClickRequest::ACTION_COMPLETE, $e);
        }
    }

    // --- answers -----------------------------------------------------------------------------

    /**
     * The error body for a request that may not even have parsed: ids echoed when present.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public static function errorResponse(array $input, int $action, ClickException $e): array
    {
        $raw = ClickRequest::rawOf($input);

        return self::body(
            $raw['click_trans_id'] ?? null,
            $raw['merchant_trans_id'] ?? null,
            $action,
            null,
            $e->clickCode,
        );
    }

    /** @return array<string, mixed> */
    private static function success(ClickRequest $request, int $action, ?int $merchantId): array
    {
        return self::body($request->clickTransId, $request->merchantTransId, $action, $merchantId, ClickException::SUCCESS);
    }

    /** @return array<string, mixed> */
    private static function body(?string $clickTransId, ?string $merchantTransId, int $action, ?int $merchantId, int $code): array
    {
        $idKey = $action === ClickRequest::ACTION_PREPARE ? 'merchant_prepare_id' : 'merchant_confirm_id';

        return [
            'click_trans_id' => $clickTransId !== null && preg_match('/^\d{1,18}$/', $clickTransId) === 1 ? (int) $clickTransId : $clickTransId,
            'merchant_trans_id' => $merchantTransId,
            $idKey => $merchantId,
            'error' => $code,
            'error_note' => ClickException::note($code),
        ];
    }

    // --- state transitions -------------------------------------------------------------------

    /**
     * Click reports a failed / canceled payment (`error < 0`): an open transaction becomes -1 with
     * the Click code as `cancel_reason`. The ORDER is not touched: one failed attempt (a payer's
     * insufficient funds) must not close it while another prepared transaction of the same order may
     * still complete, as in Payme; it stays pending until its TTL (docs/13 §4.3).
     * A canceled one answers -9 again; a completed one is NOT undone (the money was credited; a
     * refund goes through the admin panel) and answers -4.
     */
    private function cancelFromClick(PaymentOrder $order, ProviderTransaction $transaction, ClickRequest $request, CarbonImmutable $now): ClickException
    {
        if ($transaction->isPerformed()) {
            Log::warning('billing.click.cancel_after_complete', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'click_error' => $request->error]);

            return ClickException::alreadyPaid();
        }

        if ($transaction->isOpen()) {
            $transaction->fill([
                'state' => ProviderTransaction::STATE_CANCELED,
                'canceled_at' => $now,
                'cancel_time' => $now->getTimestampMs(),
                'cancel_reason' => $request->error,
                'raw' => self::raw(self::METHOD_COMPLETE, $request),
            ]);
            $transaction->pushHistory(self::METHOD_COMPLETE, ProviderTransaction::STATE_CREATED, ProviderTransaction::STATE_CANCELED, $now);
            $transaction->save();

            Log::info('billing.click.canceled', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'click_error' => $request->error, 'click_error_note' => $request->errorNote]);
        }

        return ClickException::transactionCanceled("click error {$request->error}");
    }

    /**
     * Credits the order (PaymentApplier, nested in our transaction). A refusal maps to the Click
     * code Click should see; the transaction then stays prepared and every retry gets the same
     * answer (the `failed` ledger row is a duplicate of the first attempt).
     *
     * @return array{0: Payment|null, 1: ClickException|null}
     */
    private function applyCharge(PaymentOrder $order, ProviderTransaction $transaction, CarbonImmutable $now): array
    {
        $event = new ChargeEvent(
            provider: SubscriptionProvider::Click,
            providerEventId: $transaction->provider_transaction_id.self::EVENT_COMPLETE,
            providerChargeId: $transaction->provider_transaction_id,
            orderToken: $order->token,
            amount: $transaction->amount,
            currency: 'UZS',
            isRenewal: false,
            periodEndsAt: null,
            occurredAt: $now,
            raw: [
                'source' => 'click.complete',
                'click_trans_id' => $transaction->provider_transaction_id,
                'merchant_prepare_id' => $transaction->id,
                'complete_time' => $now->getTimestampMs(),
            ],
            providerTransactionId: $transaction->id,
        );

        try {
            $payment = $this->applier->applyCharge($event, $now);
        } catch (DuplicatePaymentException $e) {
            // The ledger already has this event (a failed attempt, or a row written out of band): honour it.
            $payment = $e->payment;
        } catch (PaymentApplyException $e) {
            Log::warning('billing.click.complete_refused', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'code' => $e->errorCode]);

            return [null, $e->errorCode === PaymentApplyException::ORDER_EXPIRED
                ? ClickException::transactionCanceled($e->errorCode)
                : ClickException::alreadyPaid()];
        }

        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            $reason = $payment->raw['failure_reason'] ?? 'payment_'.$payment->status;
            $reason = is_string($reason) ? $reason : 'payment_'.$payment->status;
            Log::warning('billing.click.complete_refused', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'code' => $reason]);

            return [null, match (true) {
                $reason === 'amount_mismatch' => ClickException::incorrectAmount($reason),
                $reason === 'order_already_paid' => ClickException::alreadyPaid(),
                str_starts_with($reason, 'order_') => ClickException::transactionCanceled($reason),
                default => ClickException::updateFailed($reason),
            }];
        }

        return [$payment, null];
    }

    /** `merchant_confirm_id` of a completed transaction: the id of its ledger row. */
    private function confirmIdOf(ProviderTransaction $transaction): ?int
    {
        $id = Payment::query()
            ->where('provider', SubscriptionProvider::Click->value)
            ->where('provider_event_id', $transaction->provider_transaction_id.self::EVENT_COMPLETE)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Lock order of docs/13 §3 for one transaction: its order FOR UPDATE, then the row itself FOR UPDATE (re-read).
     *
     * @return array{PaymentOrder, ProviderTransaction}
     */
    private function lockOrderAndTransaction(ProviderTransaction $probe): array
    {
        /** @var PaymentOrder $order */
        $order = PaymentOrder::query()->whereKey($probe->payment_order_id)->lockForUpdate()->firstOrFail();
        /** @var ProviderTransaction $transaction */
        $transaction = ProviderTransaction::query()->whereKey($probe->id)->lockForUpdate()->firstOrFail();

        return [$order, $transaction];
    }

    // --- checks ------------------------------------------------------------------------------

    private function verify(ClickRequest $request): void
    {
        if (! $this->signature->verify($request)) {
            throw ClickException::signCheckFailed('sign_string or service_id does not match');
        }
    }

    private static function tiyin(ClickRequest $request): int
    {
        return ClickAmount::toTiyin($request->amount) ?? throw ClickException::incorrectAmount("amount '{$request->amount}' is not a decimal");
    }

    /** The Click order behind `merchant_trans_id`; another provider's order is "not found" too. */
    private function findOrder(string $token): PaymentOrder
    {
        $order = $this->checkout->findByToken($token);
        if ($order === null || $order->provider !== SubscriptionProvider::Click->value) {
            throw ClickException::orderNotFound($order === null ? 'no order for merchant_trans_id' : "order provider is {$order->provider}");
        }

        return $order;
    }

    private function assertPayable(PaymentOrder $order, CarbonImmutable $now): void
    {
        if (! $order->isPayableAt($now) || $this->organizations->refusal($order, $now) !== null) {
            throw $this->refusalFor($order, $now);
        }
    }

    /** The Click code for an order that cannot be paid (-4 paid, -9 closed / expired / refused for the organization). */
    private function refusalFor(PaymentOrder $order, CarbonImmutable $now): ClickException
    {
        if ($order->status === PaymentOrder::STATUS_PAID) {
            return ClickException::alreadyPaid();
        }

        if ($order->isPayableAt($now)) {
            $refusal = $this->organizations->refusal($order, $now);

            return $refusal === null
                ? ClickException::updateFailed('order became payable again')
                : ClickException::transactionCanceled($refusal);
        }

        return ClickException::transactionCanceled($order->isPending() ? 'order expired' : "order is {$order->status}");
    }

    private function find(string $clickTransId): ?ProviderTransaction
    {
        /** @var ProviderTransaction|null $transaction */
        $transaction = ProviderTransaction::query()
            ->where('provider', SubscriptionProvider::Click->value)
            ->where('provider_transaction_id', $clickTransId)
            ->first();

        return $transaction;
    }

    /**
     * The request as stored in `provider_transactions.raw`: the form without `sign_string`.
     *
     * @return array<string, mixed>
     */
    private static function raw(string $method, ClickRequest $request): array
    {
        return ['method' => $method, 'params' => $request->raw];
    }

    /** @param array<array-key, mixed> $input */
    private function logRefusal(string $method, array $input, ClickException $e): void
    {
        $raw = ClickRequest::rawOf($input);
        $context = ['method' => $method, 'code' => $e->clickCode, 'click_trans_id' => $raw['click_trans_id'] ?? null, 'detail' => $e->getMessage()];

        if ($e->clickCode === ClickException::SIGN_CHECK_FAILED) {
            Log::warning('billing.click.sign_failed', $context);
        } else {
            Log::info('billing.click.refused', $context);
        }
    }
}
