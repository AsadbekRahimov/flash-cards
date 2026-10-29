<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why an admin refund (PaymentRefundService, docs/13 §10) was not carried out. Nothing is changed
 * locally in any of these cases.
 *
 *   forbidden                 403  the actor is not an active admin
 *   not_refundable            409  not a succeeded charge / renewal with a provider charge id (`context.cause` says which: CAUSE_*)
 *   provider_cannot_refund    409  no adapter that can return the money (Payme / Click cabinet, adapter not deployed): refund there, then confirm "done by hand"
 *   provider_refund_failed    502  the adapter threw while returning the money
 */
final class PaymentRefundException extends BillingException
{
    public const FORBIDDEN = 'forbidden';

    public const NOT_REFUNDABLE = 'not_refundable';

    public const PROVIDER_CANNOT_REFUND = 'provider_cannot_refund';

    public const PROVIDER_REFUND_FAILED = 'provider_refund_failed';

    /** `not_refundable` because of the payment's state: not a succeeded charge / renewal of a known provider. */
    public const CAUSE_STATE = 'state';

    /** `not_refundable` because the ledger row has no provider charge id to give back. */
    public const CAUSE_NO_CHARGE_ID = 'no_charge_id';

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'Only an active administrator can refund a payment.');
    }

    /** @param  self::CAUSE_*  $cause */
    public static function notRefundable(string $why, string $cause): self
    {
        return new self(self::NOT_REFUNDABLE, "This payment cannot be refunded: {$why}.", ['cause' => $cause]);
    }

    public static function providerCannotRefund(string $provider): self
    {
        return new self(self::PROVIDER_CANNOT_REFUND, "Money cannot be returned through [{$provider}] from here. Return it in the provider's cabinet, then repeat with \"returned by hand\" confirmed.", ['provider' => $provider]);
    }

    public static function providerRefundFailed(string $provider): self
    {
        return new self(self::PROVIDER_REFUND_FAILED, "The provider [{$provider}] did not confirm the refund. Nothing was changed; check the provider's cabinet before retrying.", ['provider' => $provider]);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::FORBIDDEN => 403,
            self::PROVIDER_REFUND_FAILED => 502,
            default => 409,
        };
    }
}
