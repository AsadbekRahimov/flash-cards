<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Models\Payment;

/**
 * The provider event was already applied: `payments (provider, provider_event_id)` exists
 * (docs/13 §0 "Идемпотентность"). Nothing was written; `$payment` is the earlier ledger row,
 * so a webhook handler can answer exactly as it did the first time.
 */
final class DuplicatePaymentException extends BillingException
{
    public const DUPLICATE = 'duplicate_payment';

    public function __construct(public readonly Payment $payment)
    {
        parent::__construct(self::DUPLICATE, "Payment event [{$payment->provider}:{$payment->provider_event_id}] was already applied as payment #{$payment->id}.");
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
