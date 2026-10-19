<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\ChargeEvent;
use App\Domain\Billing\DTO\RefundEvent;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns normalized provider events into ledger rows and subscription changes — the idempotency
 * core of billing (docs/13 §0, §2.1, §3).
 *
 * Fast path: `payments (provider, provider_event_id)` exists -> DuplicatePaymentException, no
 * locks, no writes. Slow path, one transaction in the lock order of docs/13 §3:
 *
 *   payment_orders (FOR UPDATE by token) -> [provider_transactions: locked by the Payme / Click
 *   adapter before calling in] -> subject row (learners / organizations FOR UPDATE)
 *   -> subscriptions (live row FOR UPDATE, inside SubscriptionService::applyCharge)
 *   -> INSERT payments LAST.
 *
 * Two deliveries of one event either serialize on the order row (the second one then finds the
 * ledger row when it reaches the INSERT) or race straight into the unique index; in both cases
 * `UniqueConstraintViolationException` rolls the loser back and surfaces as
 * DuplicatePaymentException carrying the winner's row. The subscription is extended exactly once.
 *
 * Events that fit no credit (dead order, other provider, amount / currency mismatch) are
 * COMMITTED as `payments.status = failed` — the money may be with us and support must see it —
 * and the subscription is not touched; the domain exception is thrown after the commit.
 */
final class PaymentApplier
{
    public const ACTION_REFUNDED = 'payment.refunded';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Returns the succeeded ledger row, or the `failed` row for an amount / currency mismatch
     * (the provider is answered normally; the subscription is unchanged — docs/13 §2.1).
     *
     * @throws DuplicatePaymentException the event was already applied (no side effects)
     * @throws PaymentApplyException order_not_found | order_expired | order_mismatch (a `failed` ledger row is committed when the order is known)
     */
    public function applyCharge(ChargeEvent $e, ?CarbonImmutable $now = null): Payment
    {
        $now ??= CarbonImmutable::now();

        $existing = $this->existing($e->provider, $e->providerEventId);
        if ($existing !== null) {
            throw new DuplicatePaymentException($existing);
        }

        try {
            /** @var array{Payment, PaymentApplyException|null} $outcome */
            $outcome = DB::transaction(function () use ($e, $now): array {
                /** @var PaymentOrder|null $order */
                $order = PaymentOrder::query()->where('token', $e->orderToken)->lockForUpdate()->first();

                if ($order === null) {
                    throw PaymentApplyException::orderNotFound();
                }

                // Re-check under the order lock: a parallel delivery that committed while we
                // waited is answered here, before any subscription work (the unique index would
                // catch it anyway, after a rolled-back extension).
                $existing = $this->existing($e->provider, $e->providerEventId);
                if ($existing !== null) {
                    throw new DuplicatePaymentException($existing);
                }

                $kind = $e->isRenewal ? Payment::KIND_RENEWAL : Payment::KIND_CHARGE;

                if ($order->provider !== $e->provider->value) {
                    return [$this->failed($order, $e, $kind, 'provider_mismatch'), PaymentApplyException::orderMismatch("order provider is {$order->provider}")];
                }

                if (in_array($order->status, [PaymentOrder::STATUS_EXPIRED, PaymentOrder::STATUS_CANCELED, PaymentOrder::STATUS_FAILED], true)) {
                    return [$this->failed($order, $e, $kind, 'order_'.$order->status), PaymentApplyException::orderExpired($order->status)];
                }

                if ($order->status === PaymentOrder::STATUS_PAID && ! $e->isRenewal) {
                    return [$this->failed($order, $e, $kind, 'order_already_paid'), PaymentApplyException::orderMismatch('the order is already paid and this is not a renewal')];
                }

                $subject = $this->lockSubjectRow($order->subscriber_type, $order->subscriber_id);
                if ($subject === null) {
                    return [$this->failed($order, $e, $kind, 'subscriber_missing'), PaymentApplyException::orderMismatch('the subscriber no longer exists')];
                }

                if ($e->amount !== $order->amount || $e->currency !== $order->currency) {
                    Log::error('billing.amount_mismatch', [
                        'payment_order_id' => $order->id,
                        'provider' => $e->provider->value,
                        'provider_event_id' => $e->providerEventId,
                        'expected' => ['amount' => $order->amount, 'currency' => $order->currency],
                        'actual' => ['amount' => $e->amount, 'currency' => $e->currency],
                    ]);

                    return [$this->failed($order, $e, $kind, 'amount_mismatch'), null];
                }

                $periodStart = $this->periodStartFor($subject, $now);
                $subscription = $this->subscriptions->applyCharge($subject, $order, $e, $now);

                $payment = $this->insert($order, $e, [
                    'subscription_id' => $subscription->id,
                    'kind' => $kind,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'period_starts_at' => $periodStart,
                    'period_ends_at' => $subscription->current_period_ends_at,
                ]);

                $order->fill([
                    'status' => PaymentOrder::STATUS_PAID,
                    'paid_at' => $order->paid_at ?? $now,
                    'subscription_id' => $subscription->id,
                ])->save();

                if ($order->offer_id !== null) {
                    Offer::query()->whereKey($order->offer_id)->whereNull('redeemed_at')
                        ->update(['redeemed_at' => $now, 'payment_order_id' => $order->id, 'updated_at' => $now]);
                }

                $this->events->record(
                    ProductEventName::PaymentSucceeded,
                    ['payment_id' => $payment->id, 'payment_order_id' => $order->id, 'provider' => $e->provider->value, 'kind' => $kind, 'amount' => $e->amount, 'currency' => $e->currency, 'subscriber_type' => $order->subscriber_type],
                    learner: $subject instanceof Learner ? $subject : null,
                );

                return [$payment, null];
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateOrConflict($e->provider, $e->providerEventId);
        }

        [$payment, $exception] = $outcome;

        if ($exception !== null) {
            throw $exception;
        }

        return $payment;
    }

    /**
     * Refund (Stars `refunded_payment`, Payme CancelTransaction of a performed one, Click / admin
     * manual mark). Same lock order; finds the charge by `provider_charge_id`, writes a `refund`
     * row, marks the charge `refunded`, and ends the subscription now when the refunded charge
     * paid for the period that is currently running (docs/13 §2.1).
     *
     * @param  User|null  $by  the admin who initiated the refund (audit `payment.refunded`), null for a provider-initiated one
     *
     * @throws DuplicatePaymentException
     * @throws PaymentApplyException payment_not_found | already_refunded
     */
    public function applyRefund(RefundEvent $e, ?User $by = null, ?CarbonImmutable $now = null): Payment
    {
        $now ??= CarbonImmutable::now();

        $existing = $this->existing($e->provider, $e->providerEventId);
        if ($existing !== null) {
            throw new DuplicatePaymentException($existing);
        }

        try {
            return DB::transaction(function () use ($e, $by, $now): Payment {
                /** @var Payment|null $probe */
                $probe = Payment::query()
                    ->where('provider', $e->provider->value)
                    ->where('provider_charge_id', $e->refundedProviderChargeId)
                    ->whereIn('kind', [Payment::KIND_CHARGE, Payment::KIND_RENEWAL])
                    ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_REFUNDED])
                    ->orderByDesc('id')
                    ->first(['id', 'payment_order_id', 'subscriber_type', 'subscriber_id']);

                if ($probe === null) {
                    throw PaymentApplyException::paymentNotFound($e->refundedProviderChargeId);
                }

                // Lock order: the order (if the charge still has one) -> subject -> subscription -> inserts.
                if ($probe->payment_order_id !== null) {
                    PaymentOrder::query()->whereKey($probe->payment_order_id)->lockForUpdate()->value('id');
                }
                $subject = $this->lockSubjectRow($probe->subscriber_type, $probe->subscriber_id);

                /** @var Payment $charge */
                $charge = Payment::query()->whereKey($probe->id)->lockForUpdate()->firstOrFail();

                if ($charge->status === Payment::STATUS_REFUNDED) {
                    throw PaymentApplyException::alreadyRefunded($charge->id);
                }

                /** @var Subscription|null $subscription */
                $subscription = $charge->subscription_id === null ? null : Subscription::query()->whereKey($charge->subscription_id)->lockForUpdate()->first();
                $endedNow = false;

                if ($subscription !== null && $this->refundCoversCurrentPeriod($charge, $subscription, $now)) {
                    try {
                        $this->subscriptions->endNow($subscription, 'refund', $by, $now);
                        $endedNow = true;
                    } catch (SubscriptionException) {
                        // Already expired: nothing to end.
                    }
                }

                $charge->fill(['status' => Payment::STATUS_REFUNDED])->save();

                $refund = Payment::query()->create([
                    'payment_order_id' => $charge->payment_order_id,
                    'provider_transaction_id' => $charge->provider_transaction_id,
                    'subscription_id' => $charge->subscription_id,
                    'subscriber_type' => $charge->subscriber_type,
                    'subscriber_id' => $charge->subscriber_id,
                    'provider' => $e->provider->value,
                    'provider_event_id' => $e->providerEventId,
                    'provider_charge_id' => $e->refundedProviderChargeId,
                    'kind' => Payment::KIND_REFUND,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'amount' => $e->amount,
                    'currency' => $e->currency,
                    'refunded_payment_id' => $charge->id,
                    'period_starts_at' => $charge->period_starts_at,
                    'period_ends_at' => $charge->period_ends_at,
                    'occurred_at' => $e->occurredAt,
                    'raw' => $e->raw,
                ]);

                if ($by !== null) {
                    AuditLog::create([
                        'user_id' => $by->id,
                        'action' => self::ACTION_REFUNDED,
                        'entity_type' => Payment::class,
                        'entity_id' => $charge->id,
                        'ip' => null,
                        'meta' => [
                            'refund_payment_id' => $refund->id,
                            'subscriber_type' => $charge->subscriber_type,
                            'subscriber_id' => $charge->subscriber_id,
                            'provider' => $e->provider->value,
                            'amount' => $e->amount,
                            'currency' => $e->currency,
                            'subscription_ended' => $endedNow,
                        ],
                    ]);
                }

                $this->events->record(
                    ProductEventName::PaymentRefunded,
                    ['payment_id' => $charge->id, 'refund_payment_id' => $refund->id, 'payment_order_id' => $charge->payment_order_id, 'provider' => $e->provider->value, 'amount' => $e->amount, 'currency' => $e->currency, 'subscription_ended' => $endedNow, 'subscriber_type' => $charge->subscriber_type],
                    learner: $subject instanceof Learner ? $subject : null,
                    user: $by,
                );

                return $refund;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateOrConflict($e->provider, $e->providerEventId);
        }
    }

    /**
     * A `failed` ledger row for money that arrived for a subject we know but cannot credit
     * (orphan Stars renewal whose order is gone, docs/13 §4.1). Idempotent by the unique key.
     *
     * @throws DuplicatePaymentException
     */
    public function recordOrphan(ChargeEvent $e, Learner|Organization $subscriber, string $reason): Payment
    {
        $existing = $this->existing($e->provider, $e->providerEventId);
        if ($existing !== null) {
            throw new DuplicatePaymentException($existing);
        }

        Log::error('billing.payment_orphan', ['provider' => $e->provider->value, 'provider_event_id' => $e->providerEventId, 'reason' => $reason, 'subscriber_type' => SubscriptionService::subscriberType($subscriber), 'subscriber_id' => $subscriber->id]);

        try {
            return DB::transaction(function () use ($e, $subscriber, $reason): Payment {
                $payment = Payment::query()->create([
                    'subscriber_type' => SubscriptionService::subscriberType($subscriber),
                    'subscriber_id' => $subscriber->id,
                    'provider' => $e->provider->value,
                    'provider_event_id' => $e->providerEventId,
                    'provider_charge_id' => $e->providerChargeId,
                    'provider_transaction_id' => $e->providerTransactionId,
                    'kind' => $e->isRenewal ? Payment::KIND_RENEWAL : Payment::KIND_CHARGE,
                    'status' => Payment::STATUS_FAILED,
                    'amount' => $e->amount,
                    'currency' => $e->currency,
                    'occurred_at' => $e->occurredAt,
                    'raw' => [...$e->raw, 'failure_reason' => $reason],
                ]);

                $this->recordFailed($payment, null, $reason, $subscriber instanceof Learner ? $subscriber : null);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateOrConflict($e->provider, $e->providerEventId);
        }
    }

    private function existing(SubscriptionProvider $provider, string $providerEventId): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()->where('provider', $provider->value)->where('provider_event_id', $providerEventId)->first();

        return $payment;
    }

    private function duplicateOrConflict(SubscriptionProvider $provider, string $providerEventId): DuplicatePaymentException|PaymentApplyException
    {
        $existing = $this->existing($provider, $providerEventId);

        return $existing !== null ? new DuplicatePaymentException($existing) : PaymentApplyException::orderMismatch('a conflicting ledger row exists');
    }

    /** The `failed` ledger row + `payment.failed` event for an event that fits no credit. */
    private function failed(PaymentOrder $order, ChargeEvent $e, string $kind, string $reason): Payment
    {
        $payment = $this->insert($order, $e, [
            'subscription_id' => null,
            'kind' => $kind,
            'status' => Payment::STATUS_FAILED,
            'period_starts_at' => null,
            'period_ends_at' => null,
        ], $reason);

        $this->recordFailed($payment, $order, $reason, $order->subscriber_type === 'learner' ? Learner::withTrashed()->find($order->subscriber_id) : null);

        return $payment;
    }

    private function recordFailed(Payment $payment, ?PaymentOrder $order, string $reason, ?Learner $learner): void
    {
        $this->events->record(
            ProductEventName::PaymentFailed,
            ['payment_id' => $payment->id, 'payment_order_id' => $order?->id, 'provider' => $payment->provider, 'reason' => $reason, 'amount' => $payment->amount, 'currency' => $payment->currency, 'subscriber_type' => $payment->subscriber_type],
            learner: $learner,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function insert(PaymentOrder $order, ChargeEvent $e, array $attributes, ?string $failureReason = null): Payment
    {
        return Payment::query()->create([
            'payment_order_id' => $order->id,
            'provider_transaction_id' => $e->providerTransactionId,
            'subscriber_type' => $order->subscriber_type,
            'subscriber_id' => $order->subscriber_id,
            'provider' => $e->provider->value,
            'provider_event_id' => $e->providerEventId,
            'provider_charge_id' => $e->providerChargeId,
            'amount' => $e->amount,
            'currency' => $e->currency,
            'occurred_at' => $e->occurredAt,
            'raw' => $failureReason === null ? $e->raw : [...$e->raw, 'failure_reason' => $failureReason],
            ...$attributes,
        ]);
    }

    /** FOR UPDATE on the subject row; null when it is gone. */
    private function lockSubjectRow(string $type, int $id): Learner|Organization|null
    {
        if ($type === 'organization') {
            /** @var Organization|null $organization */
            $organization = Organization::query()->whereKey($id)->lockForUpdate()->first();

            return $organization;
        }

        /** @var Learner|null $learner */
        $learner = Learner::withTrashed()->whereKey($id)->lockForUpdate()->first();

        return $learner;
    }

    /** Start of the period this charge pays for: the current live end when it is in the future, else now. */
    private function periodStartFor(Learner|Organization $subject, CarbonImmutable $now): CarbonImmutable
    {
        $live = $this->subscriptions->liveFor($subject);

        if ($live === null || $live->current_period_ends_at === null) {
            return $now;
        }

        $end = CarbonImmutable::instance($live->current_period_ends_at);

        return $end->greaterThan($now) ? $end : $now;
    }

    /** The refunded charge paid (part of) the period the subscription is in right now. */
    private function refundCoversCurrentPeriod(Payment $charge, Subscription $subscription, CarbonImmutable $now): bool
    {
        if (! SubscriptionLiveness::isLive($subscription, $now)) {
            return false;
        }

        if ($charge->period_ends_at === null) {
            return true;
        }

        return CarbonImmutable::instance($charge->period_ends_at)->greaterThan($now);
    }
}
