<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Payme;

use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\DTO\RefundEvent;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Payme Merchant API, JSON-RPC 2.0 (docs/13 §4.2): the six methods and the state machine of
 * `provider_transactions` for `provider = payme` (1 created, 2 performed, -1 canceled before
 * perform, -2 canceled after perform).
 *
 * Every state change runs in one transaction in the lock order of docs/13 §3:
 * `payment_orders` FOR UPDATE -> `provider_transactions` FOR UPDATE -> (PaymentApplier: subject
 * -> subscription -> INSERT payments). Two parallel deliveries of the same call therefore
 * serialize on the order row; the second one sees the final state and answers exactly like the
 * first (idempotency: a repeated CreateTransaction returns the same `create_time`/`transaction`,
 * a repeated PerformTransaction the same `perform_time`, a repeated CancelTransaction the same
 * `cancel_time`).
 *
 * Business errors are PaymeRpcException (HTTP 200 + `error`), raised AFTER the transaction has
 * committed whatever had to be recorded first (a timed-out transaction is canceled with reason 4
 * and then refused with -31008). Infrastructure failures propagate: the controller answers
 * HTTP 500 and Payme retries.
 *
 * Deviation from docs/13 §4.2: CancelTransaction before perform leaves the order `pending` — the
 * payer retries on the same checkout page (Payme opens a new transaction on the same
 * `account.order_id`) until the order's own TTL; the order is never marked `canceled` by Payme.
 */
final class PaymeRpcService
{
    public const METHOD_CHECK_PERFORM = 'CheckPerformTransaction';

    public const METHOD_CREATE = 'CreateTransaction';

    public const METHOD_PERFORM = 'PerformTransaction';

    public const METHOD_CANCEL = 'CancelTransaction';

    public const METHOD_CHECK = 'CheckTransaction';

    public const METHOD_STATEMENT = 'GetStatement';

    /** `payments.provider_event_id` suffixes (docs/13 §1.2). */
    public const EVENT_PERFORM = ':perform';

    public const EVENT_CANCEL = ':cancel';

    private const MAX_TRANSACTION_ID_LENGTH = 64;

    public function __construct(
        private readonly ProviderTransactionService $transactions,
        private readonly PaymentApplier $applier,
        private readonly CheckoutService $checkout,
        private readonly OrganizationOrderGuard $organizations,
    ) {}

    /**
     * Dispatches one authenticated JSON-RPC request and returns the full response envelope.
     *
     * @param  array<array-key, mixed>  $rpc  the decoded request body
     * @return array{jsonrpc: string, id: mixed, result?: array<string, mixed>, error?: array<string, mixed>}
     */
    public function handle(array $rpc, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $id = $rpc['id'] ?? null;
        $id = is_int($id) || is_string($id) ? $id : null;

        try {
            $method = $rpc['method'] ?? null;
            if (! is_string($method) || $method === '') {
                throw PaymeRpcException::invalidRequest('method is missing');
            }

            $params = $rpc['params'] ?? [];
            if (! is_array($params)) {
                throw PaymeRpcException::invalidRequest('params must be an object');
            }

            /** @var array<string, mixed> $params */
            $result = match ($method) {
                self::METHOD_CHECK_PERFORM => $this->checkPerformTransaction($params, $now),
                self::METHOD_CREATE => $this->createTransaction($params, $now),
                self::METHOD_PERFORM => $this->performTransaction($params, $now),
                self::METHOD_CANCEL => $this->cancelTransaction($params, $now),
                self::METHOD_CHECK => $this->checkTransaction($params),
                self::METHOD_STATEMENT => $this->getStatement($params),
                default => throw PaymeRpcException::methodNotFound($method),
            };

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (PaymeRpcException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $e->toArray()];
        }
    }

    /**
     * `{amount, account.order_id}` -> `{allow: true[, detail]}`: the order exists, is payable
     * through Payme (for an organization: its tier is still the one to sell and the organization is
     * active — -31053 / -31054, 2B.8), the amount matches and no other open transaction holds it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function checkPerformTransaction(array $params, CarbonImmutable $now): array
    {
        $token = self::accountOrderId($params);
        $amount = self::amount($params);

        $order = $this->checkout->findByToken($token) ?? throw PaymeRpcException::orderNotFound();
        $this->assertPayable($order, $now);

        if ($amount !== $order->amount) {
            throw PaymeRpcException::wrongAmount();
        }

        $refusal = $this->organizations->refusal($order, $now);
        if ($refusal !== null) {
            throw PaymeRpcException::forRefusal($refusal);
        }

        $this->cancelTimedOutOpenTransaction($order, $now, self::METHOD_CHECK_PERFORM);

        /** @var ProviderTransaction|null $open */
        $open = $order->providerTransactions()->where('state', ProviderTransaction::STATE_CREATED)->first();
        if ($open !== null) {
            throw PaymeRpcException::orderBusy($open->provider_transaction_id);
        }

        $result = ['allow' => true];
        $detail = $this->fiscalDetail($order);
        if ($detail !== null) {
            $result['detail'] = $detail;
        }

        return $result;
    }

    /**
     * `{id, time, amount, account}` -> `{create_time, transaction, state: 1}`. A known `id` is
     * answered from its row (state 1: the same result; anything else: -31008); a new one re-runs
     * the CheckPerformTransaction checks under the order lock (ProviderTransactionService::open,
     * the organization guard included) and refuses a `time` older than the transaction timeout.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createTransaction(array $params, CarbonImmutable $now): array
    {
        $id = self::transactionId($params);
        $time = self::integer($params, 'time');
        $amount = self::amount($params);
        $token = self::accountOrderId($params);

        $existing = $this->find($id);
        if ($existing !== null) {
            return $this->answerExistingCreate($existing, $now);
        }

        if ($now->getTimestampMs() - $time > $this->timeoutMs()) {
            throw PaymeRpcException::cannotPerform('transaction time is older than the timeout');
        }

        $order = $this->checkout->findByToken($token) ?? throw PaymeRpcException::orderNotFound();
        $this->cancelTimedOutOpenTransaction($order, $now, self::METHOD_CREATE);

        try {
            $transaction = $this->transactions->open(
                SubscriptionProvider::Payme,
                $id,
                $token,
                $amount,
                $time,
                self::raw(self::METHOD_CREATE, $params),
                $now,
                guard: $this->organizations->refusal(...),
            );
        } catch (ProviderTransactionException $e) {
            throw match ($e->errorCode) {
                ProviderTransactionException::ORDER_NOT_FOUND => PaymeRpcException::orderNotFound(),
                ProviderTransactionException::AMOUNT_MISMATCH => PaymeRpcException::wrongAmount(),
                ProviderTransactionException::ORDER_BUSY => PaymeRpcException::orderBusy(self::busyTransactionId($e)),
                default => PaymeRpcException::forRefusal($e->reason ?? $e->getMessage()),
            };
        }

        // open() returns the existing row when the same id raced us: answer from its state.
        return $this->answerExistingCreate($transaction, $now);
    }

    /**
     * `{id}` -> `{transaction, perform_time, state: 2}`. The money is credited through
     * PaymentApplier::applyCharge inside the same transaction (docs/13 §3); a repeat returns the
     * stored `perform_time`; a timed-out open transaction is canceled (reason 4) and refused.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function performTransaction(array $params, CarbonImmutable $now): array
    {
        $id = self::transactionId($params);
        $probe = $this->find($id) ?? throw PaymeRpcException::transactionNotFound($id);

        /** @var array{0: array<string, mixed>|null, 1: PaymeRpcException|null} $outcome */
        $outcome = DB::transaction(function () use ($probe, $params, $now): array {
            [$order, $transaction] = $this->lockOrderAndTransaction($probe);

            if ($transaction->isPerformed()) {
                return [$this->performResult($transaction), null];
            }

            if ($transaction->isCanceled()) {
                return [null, PaymeRpcException::cannotPerform("transaction is canceled (state {$transaction->state})")];
            }

            if ($transaction->isTimedOutAt($now, $this->timeoutMs())) {
                $this->cancel($transaction, ProviderTransaction::REASON_TIMEOUT, self::METHOD_PERFORM, $now, null);

                return [null, PaymeRpcException::cannotPerform('transaction timed out')];
            }

            $failure = $this->applyCharge($order, $transaction, $now);
            if ($failure !== null) {
                return [null, $failure];
            }

            $transaction->fill([
                'state' => ProviderTransaction::STATE_PERFORMED,
                'performed_at' => $now,
                'perform_time' => $now->getTimestampMs(),
                'raw' => self::raw(self::METHOD_PERFORM, $params),
            ]);
            $transaction->pushHistory(self::METHOD_PERFORM, ProviderTransaction::STATE_CREATED, ProviderTransaction::STATE_PERFORMED, $now);
            $transaction->save();

            return [$this->performResult($transaction), null];
        });

        [$result, $error] = $outcome;
        if ($error !== null) {
            throw $error;
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * `{id, reason}` -> `{transaction, cancel_time, state}`. Before perform: state -1 (the order
     * stays pending). After perform: the refund is recorded through PaymentApplier::applyRefund
     * (ending the subscription when the refunded period is the current one), state -2. Canceled
     * already: the stored answer.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function cancelTransaction(array $params, CarbonImmutable $now): array
    {
        $id = self::transactionId($params);
        $reason = self::integer($params, 'reason');
        $probe = $this->find($id) ?? throw PaymeRpcException::transactionNotFound($id);

        /** @var array{0: array<string, mixed>|null, 1: PaymeRpcException|null} $outcome */
        $outcome = DB::transaction(function () use ($probe, $params, $reason, $now): array {
            [$order, $transaction] = $this->lockOrderAndTransaction($probe);

            if ($transaction->isCanceled()) {
                return [$this->cancelResult($transaction), null];
            }

            if ($transaction->isPerformed()) {
                $window = $this->cancelAfterPerformDays();
                if ($window !== null && $transaction->performed_at !== null && CarbonImmutable::instance($transaction->performed_at)->addDays($window)->lessThan($now)) {
                    return [null, PaymeRpcException::cannotCancel("performed more than {$window} days ago")];
                }

                $this->applyRefund($order, $transaction, $reason, $now);
            }

            $this->cancel($transaction, $reason, self::METHOD_CANCEL, $now, self::raw(self::METHOD_CANCEL, $params));

            return [$this->cancelResult($transaction), null];
        });

        [$result, $error] = $outcome;
        if ($error !== null) {
            throw $error;
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * `{id}` -> `{create_time, perform_time, cancel_time, transaction, state, reason}` as stored.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function checkTransaction(array $params): array
    {
        $id = self::transactionId($params);
        $transaction = $this->find($id) ?? throw PaymeRpcException::transactionNotFound($id);

        return [
            'create_time' => $transaction->provider_time ?? 0,
            'perform_time' => $transaction->perform_time ?? 0,
            'cancel_time' => $transaction->cancel_time ?? 0,
            'transaction' => (string) $transaction->id,
            'state' => $transaction->state,
            'reason' => $transaction->cancel_reason,
        ];
    }

    /**
     * `{from, to}` (ms) -> every Payme transaction whose `time` (= `create_time`) lies in the range.
     *
     * @param  array<string, mixed>  $params
     * @return array{transactions: list<array<string, mixed>>}
     */
    public function getStatement(array $params): array
    {
        $from = self::integer($params, 'from');
        $to = self::integer($params, 'to');
        if ($from > $to) {
            throw PaymeRpcException::invalidRequest('from is after to');
        }

        /** @var Collection<int, ProviderTransaction> $rows */
        $rows = ProviderTransaction::query()
            ->with('paymentOrder:id,token')
            ->where('provider', SubscriptionProvider::Payme->value)
            ->whereBetween('provider_time', [$from, $to])
            ->orderBy('provider_time')
            ->orderBy('id')
            ->get();

        $transactions = [];
        foreach ($rows as $row) {
            $transactions[] = [
                'id' => $row->provider_transaction_id,
                'time' => $row->provider_time ?? 0,
                'amount' => $row->amount,
                'account' => [PaymeRpcException::ACCOUNT_FIELD => $row->paymentOrder?->token],
                'create_time' => $row->provider_time ?? 0,
                'perform_time' => $row->perform_time ?? 0,
                'cancel_time' => $row->cancel_time ?? 0,
                'transaction' => (string) $row->id,
                'state' => $row->state,
                'reason' => $row->cancel_reason,
                'receivers' => null,
            ];
        }

        return ['transactions' => $transactions];
    }

    // --- state transitions -------------------------------------------------------------------

    /**
     * CreateTransaction for an id we already know: the same answer while open, -31008 otherwise (timed out: canceled first).
     *
     * @return array<string, mixed>
     */
    private function answerExistingCreate(ProviderTransaction $probe, CarbonImmutable $now): array
    {
        /** @var array{0: array<string, mixed>|null, 1: PaymeRpcException|null} $outcome */
        $outcome = DB::transaction(function () use ($probe, $now): array {
            [, $transaction] = $this->lockOrderAndTransaction($probe);

            if ($transaction->isOpen() && $transaction->isTimedOutAt($now, $this->timeoutMs())) {
                $this->cancel($transaction, ProviderTransaction::REASON_TIMEOUT, self::METHOD_CREATE, $now, null);

                return [null, PaymeRpcException::cannotPerform('transaction timed out')];
            }

            if (! $transaction->isOpen()) {
                return [null, PaymeRpcException::cannotPerform("transaction is not active (state {$transaction->state})")];
            }

            return [['create_time' => $transaction->provider_time ?? 0, 'transaction' => (string) $transaction->id, 'state' => ProviderTransaction::STATE_CREATED], null];
        });

        [$result, $error] = $outcome;
        if ($error !== null) {
            throw $error;
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Credits the order (PaymentApplier, nested in our transaction: same order lock, then subject
     * -> subscription -> payments). Returns the RPC error to answer when the money cannot be
     * credited; the transaction then stays open and Payme's retries get the same answer until
     * Payme cancels it.
     */
    private function applyCharge(PaymentOrder $order, ProviderTransaction $transaction, CarbonImmutable $now): ?PaymeRpcException
    {
        $event = new ChargeEvent(
            provider: SubscriptionProvider::Payme,
            providerEventId: $transaction->provider_transaction_id.self::EVENT_PERFORM,
            providerChargeId: $transaction->provider_transaction_id,
            orderToken: $order->token,
            amount: $transaction->amount,
            currency: 'UZS',
            isRenewal: false,
            periodEndsAt: null,
            occurredAt: $now,
            raw: [
                'source' => 'payme.PerformTransaction',
                'transaction_id' => $transaction->provider_transaction_id,
                'create_time' => $transaction->provider_time,
                'perform_time' => $now->getTimestampMs(),
            ],
            providerTransactionId: $transaction->id,
        );

        try {
            $payment = $this->applier->applyCharge($event, $now);
        } catch (DuplicatePaymentException $e) {
            // The ledger already has this event (a failed attempt, or a row written out of band): honour it.
            $payment = $e->payment;
        } catch (PaymentApplyException $e) {
            Log::warning('billing.payme.perform_refused', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'code' => $e->errorCode]);

            return PaymeRpcException::cannotPerform($e->errorCode);
        }

        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            Log::warning('billing.payme.perform_refused', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'code' => 'payment_'.$payment->status]);

            return PaymeRpcException::cannotPerform('payment_'.$payment->status);
        }

        return null;
    }

    /**
     * Records the refund of a performed transaction. Payme has already returned the money, so a
     * ledger that cannot take the refund row (charge missing or refunded by hand) is logged and
     * the cancel still proceeds to state -2.
     */
    private function applyRefund(PaymentOrder $order, ProviderTransaction $transaction, int $reason, CarbonImmutable $now): void
    {
        $event = new RefundEvent(
            provider: SubscriptionProvider::Payme,
            providerEventId: $transaction->provider_transaction_id.self::EVENT_CANCEL,
            refundedProviderChargeId: $transaction->provider_transaction_id,
            amount: $transaction->amount,
            currency: 'UZS',
            occurredAt: $now,
            raw: [
                'source' => 'payme.CancelTransaction',
                'transaction_id' => $transaction->provider_transaction_id,
                'reason' => $reason,
                'cancel_time' => $now->getTimestampMs(),
            ],
        );

        try {
            $this->applier->applyRefund($event, null, $now);
        } catch (DuplicatePaymentException) {
            // The same cancel delivered twice before our state change landed: already recorded.
        } catch (PaymentApplyException $e) {
            Log::error('billing.payme.refund_unrecorded', ['provider_transaction_id' => $transaction->id, 'payment_order_id' => $order->id, 'code' => $e->errorCode]);
        }
    }

    /** @param array<string, mixed>|null $raw the request to store as the last provider request (null = keep) */
    private function cancel(ProviderTransaction $transaction, int $reason, string $method, CarbonImmutable $now, ?array $raw): void
    {
        $from = $transaction->state;
        $to = $transaction->isPerformed() ? ProviderTransaction::STATE_CANCELED_AFTER_PERFORM : ProviderTransaction::STATE_CANCELED;

        $transaction->fill([
            'state' => $to,
            'canceled_at' => $now,
            'cancel_time' => $now->getTimestampMs(),
            'cancel_reason' => $reason,
            ...($raw === null ? [] : ['raw' => $raw]),
        ]);
        $transaction->pushHistory($method, $from, $to, $now);
        $transaction->save();
    }

    /** Closes an open transaction of this order that outlived the timeout (so a new one may be opened). */
    private function cancelTimedOutOpenTransaction(PaymentOrder $order, CarbonImmutable $now, string $method): void
    {
        /** @var ProviderTransaction|null $stale */
        $stale = $order->providerTransactions()->where('state', ProviderTransaction::STATE_CREATED)->first();
        if ($stale === null || ! $stale->isTimedOutAt($now, $this->timeoutMs())) {
            return;
        }

        DB::transaction(function () use ($stale, $now, $method): void {
            [, $transaction] = $this->lockOrderAndTransaction($stale);

            if ($transaction->isTimedOutAt($now, $this->timeoutMs())) {
                $this->cancel($transaction, ProviderTransaction::REASON_TIMEOUT, $method, $now, null);
            }
        });
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

    // --- answers -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function performResult(ProviderTransaction $transaction): array
    {
        return ['transaction' => (string) $transaction->id, 'perform_time' => $transaction->perform_time ?? 0, 'state' => $transaction->state];
    }

    /** @return array<string, mixed> */
    private function cancelResult(ProviderTransaction $transaction): array
    {
        return ['transaction' => (string) $transaction->id, 'cancel_time' => $transaction->cancel_time ?? 0, 'state' => $transaction->state];
    }

    /**
     * Fiscal receipt lines for CheckPerformTransaction (docs/13 §4.2): only when the owner has
     * configured the IKPU code; shape to be confirmed in the sandbox (§15).
     *
     * @return array<string, mixed>|null
     */
    private function fiscalDetail(PaymentOrder $order): ?array
    {
        /** @var array{ikpu?: string, package_code?: string, vat_percent?: int} $fiscal */
        $fiscal = (array) config('billing.providers.payme.fiscal', []);
        $ikpu = trim((string) ($fiscal['ikpu'] ?? ''));
        if ($ikpu === '') {
            return null;
        }

        $locale = $order->meta['learner_locale'] ?? null;
        $locale = is_string($locale) && in_array($locale, (array) config('localization.supported', ['ru']), true) ? $locale : 'ru';

        return [
            'receipt_type' => 0,
            'items' => [[
                'title' => __('billing.payme.receipt_item', ['period' => __("billing.period.{$order->period}", [], $locale)], $locale),
                'price' => $order->amount,
                'count' => 1,
                'code' => $ikpu,
                'package_code' => (string) ($fiscal['package_code'] ?? ''),
                'vat_percent' => (int) ($fiscal['vat_percent'] ?? 0),
            ]],
        ];
    }

    // --- helpers -----------------------------------------------------------------------------

    private function find(string $providerTransactionId): ?ProviderTransaction
    {
        /** @var ProviderTransaction|null $transaction */
        $transaction = ProviderTransaction::query()
            ->where('provider', SubscriptionProvider::Payme->value)
            ->where('provider_transaction_id', $providerTransactionId)
            ->first();

        return $transaction;
    }

    private function assertPayable(PaymentOrder $order, CarbonImmutable $now): void
    {
        if ($order->provider !== SubscriptionProvider::Payme->value) {
            throw PaymeRpcException::orderNotPayable("order provider is {$order->provider}");
        }

        if (! $order->isPayableAt($now)) {
            throw PaymeRpcException::orderNotPayable($order->isPending() ? 'order expired' : "order is {$order->status}");
        }
    }

    private function timeoutMs(): int
    {
        return max(1, (int) config('billing.providers.payme.transaction_timeout_ms', 43_200_000));
    }

    /** Days after perform during which Payme may still cancel (refund); null = always (owner's refund policy, docs/13 §12). */
    private function cancelAfterPerformDays(): ?int
    {
        $days = config('billing.providers.payme.cancel_after_perform_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * The request as stored in `provider_transactions.raw`: method and params only, never headers.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function raw(string $method, array $params): array
    {
        return ['method' => $method, 'params' => $params];
    }

    /** @param array<string, mixed> $params */
    private static function transactionId(array $params): string
    {
        $id = $params['id'] ?? null;
        if (! is_scalar($id) || is_bool($id) || (string) $id === '' || strlen((string) $id) > self::MAX_TRANSACTION_ID_LENGTH) {
            throw PaymeRpcException::invalidRequest('id must be a non-empty string');
        }

        return (string) $id;
    }

    /** @param array<string, mixed> $params */
    private static function accountOrderId(array $params): string
    {
        $account = $params['account'] ?? null;
        $token = is_array($account) ? ($account[PaymeRpcException::ACCOUNT_FIELD] ?? null) : null;

        if (! is_scalar($token) || is_bool($token) || (string) $token === '') {
            throw PaymeRpcException::orderNotFound();
        }

        return (string) $token;
    }

    /**
     * Payme sends integers; a non-integer amount can never match an order -> -31001.
     *
     * @param  array<string, mixed>  $params
     */
    private static function amount(array $params): int
    {
        $amount = $params['amount'] ?? null;
        if (is_int($amount)) {
            return $amount;
        }
        if (is_string($amount) && preg_match('/^\d{1,18}$/', $amount) === 1) {
            return (int) $amount;
        }
        if (is_float($amount) && floor($amount) === $amount && $amount >= 0 && $amount < PHP_INT_MAX) {
            return (int) $amount;
        }

        throw PaymeRpcException::wrongAmount();
    }

    /** @param array<string, mixed> $params */
    private static function integer(array $params, string $key): int
    {
        $value = $params[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        throw PaymeRpcException::invalidRequest("{$key} must be an integer");
    }

    /** The id of the transaction that holds the order, from ProviderTransactionException::orderBusy's message. */
    private static function busyTransactionId(ProviderTransactionException $e): string
    {
        return preg_match('/\[([^\]]+)\]/', $e->getMessage(), $m) === 1 ? $m[1] : 'unknown';
    }
}
