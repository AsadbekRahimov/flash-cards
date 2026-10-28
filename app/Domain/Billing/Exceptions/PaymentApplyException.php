<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why a provider event could not be applied (PaymentApplier, docs/13 §2.1):
 *
 *   order_not_found     404  no `payment_orders` row for the token
 *   order_expired       410  the order was expired / canceled / failed before the money arrived
 *   order_mismatch      409  the event does not fit the order (other provider, an already paid one-off order, missing subscriber)
 *   payment_not_found   404  refund of a charge we never recorded
 *   already_refunded    409  the charge was already refunded by another event
 */
final class PaymentApplyException extends BillingException
{
    public const ORDER_NOT_FOUND = 'order_not_found';

    public const ORDER_EXPIRED = 'order_expired';

    public const ORDER_MISMATCH = 'order_mismatch';

    public const PAYMENT_NOT_FOUND = 'payment_not_found';

    public const ALREADY_REFUNDED = 'already_refunded';

    public static function orderNotFound(): self
    {
        return new self(self::ORDER_NOT_FOUND, 'Payment order not found.');
    }

    public static function orderExpired(string $status): self
    {
        return new self(self::ORDER_EXPIRED, "Payment order is no longer payable (status {$status}).");
    }

    public static function orderMismatch(string $why): self
    {
        return new self(self::ORDER_MISMATCH, "Payment event does not match its order: {$why}.");
    }

    public static function paymentNotFound(string $chargeId): self
    {
        return new self(self::PAYMENT_NOT_FOUND, "No succeeded payment with provider charge id [{$chargeId}].", ['charge' => $chargeId]);
    }

    public static function alreadyRefunded(int $paymentId): self
    {
        return new self(self::ALREADY_REFUNDED, "Payment #{$paymentId} is already refunded.", ['id' => $paymentId]);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::ORDER_NOT_FOUND, self::PAYMENT_NOT_FOUND => 404,
            self::ORDER_EXPIRED => 410,
            default => 409,
        };
    }
}
