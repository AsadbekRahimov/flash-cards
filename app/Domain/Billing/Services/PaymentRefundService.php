<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\RefundEvent;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\DuplicatePaymentException;
use App\Domain\Billing\Exceptions\PaymentApplyException;
use App\Domain\Billing\Exceptions\PaymentRefundException;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refund started by a superadmin in the panel (docs/13 §10 "Фрод возвратов": admin only, with a
 * `note`, audited as `payment.refunded`).
 *
 *   1. Pre-check without side effects: a succeeded charge / renewal with a provider charge id
 *      (an already refunded one is `already_refunded` — the provider is not called again).
 *   2. Money back at the provider, OUTSIDE any transaction (docs/13 §3 rule 2), through
 *      {@see BillingProviderRegistry}: `BillingProvider::refund()` (Stars `refundStarPayment`).
 *      Payme / Click (and an adapter that is not deployed yet) cannot be called from here —
 *      the admin returns the money in the provider's cabinet and repeats the action with
 *      `$returnedByHand = true`, which skips the call and only records the fact locally.
 *   3. {@see PaymentApplier::applyRefund()} with a deterministic event id `admin-refund:<payment id>`:
 *      a second click or a second admin lands on the ledger's unique key / `already_refunded`
 *      and never produces a second refund row.
 *
 * If step 3 fails after step 2 succeeded the money is already back while the ledger is not: it
 * is logged as an error (`billing.admin_refund_unrecorded`) and the retry must use `returnedByHand`.
 */
final class PaymentRefundService
{
    public const EVENT_PREFIX = 'admin-refund:';

    public function __construct(
        private readonly BillingProviderRegistry $providers,
        private readonly PaymentApplier $applier,
    ) {}

    /**
     * @return Payment the new `refund` ledger row
     *
     * @throws PaymentRefundException
     * @throws PaymentApplyException already_refunded | payment_not_found
     */
    public function refund(Payment $payment, User $by, string $note, bool $returnedByHand = false, ?string $ip = null, ?CarbonImmutable $now = null): Payment
    {
        if (! $by->is_active || ! $by->hasRole('admin')) {
            throw PaymentRefundException::forbidden();
        }

        $payment = Payment::query()->findOrFail($payment->id);
        $provider = SubscriptionProvider::tryFrom($payment->provider);

        if ($payment->status === Payment::STATUS_REFUNDED) {
            throw PaymentApplyException::alreadyRefunded($payment->id);
        }

        if ($provider === null || ! in_array($payment->kind, [Payment::KIND_CHARGE, Payment::KIND_RENEWAL], true) || $payment->status !== Payment::STATUS_SUCCEEDED) {
            throw PaymentRefundException::notRefundable('only a succeeded charge or renewal can be refunded');
        }

        $chargeId = $payment->provider_charge_id;
        if ($chargeId === null || $chargeId === '') {
            throw PaymentRefundException::notRefundable('it has no provider charge id');
        }

        if (! $returnedByHand) {
            $this->refundAtProvider($provider, $payment);
        }

        $event = new RefundEvent(
            provider: $provider,
            providerEventId: self::EVENT_PREFIX.$payment->id,
            refundedProviderChargeId: $chargeId,
            amount: $payment->amount,
            currency: $payment->currency,
            occurredAt: $now ?? CarbonImmutable::now(),
            raw: ['source' => 'admin', 'by_user_id' => $by->id, 'returned_by_hand' => $returnedByHand, 'note' => $note],
        );

        try {
            return $this->applier->applyRefund($event, $by, $now, $note, $ip);
        } catch (DuplicatePaymentException) {
            // Another click / admin recorded this very refund first.
            throw PaymentApplyException::alreadyRefunded($payment->id);
        } catch (Throwable $e) {
            $alreadyRefunded = $e instanceof PaymentApplyException && $e->errorCode === PaymentApplyException::ALREADY_REFUNDED;

            if (! $returnedByHand && ! $alreadyRefunded) {
                Log::error('billing.admin_refund_unrecorded', ['payment_id' => $payment->id, 'provider' => $payment->provider, 'by_user_id' => $by->id, 'error' => $e::class]);
            }

            throw $e;
        }
    }

    private function refundAtProvider(SubscriptionProvider $provider, Payment $payment): void
    {
        try {
            $this->providers->for($provider)->refund($payment);
        } catch (ProviderCapabilityException) {
            throw PaymentRefundException::providerCannotRefund($provider->value);
        } catch (Throwable $e) {
            Log::error('billing.admin_refund_provider_failed', ['payment_id' => $payment->id, 'provider' => $provider->value, 'error' => $e::class]);

            throw PaymentRefundException::providerRefundFailed($provider->value);
        }
    }
}
