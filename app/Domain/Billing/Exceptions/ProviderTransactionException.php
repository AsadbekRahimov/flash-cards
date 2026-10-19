<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why a provider transaction could not be opened on an order (ProviderTransactionService::open,
 * docs/13 §4.2 CreateTransaction / §4.3 Prepare). The adapters map these to the provider's codes
 * (Payme -31050/-31051/-31001/-31052, Click -5/-4/-2):
 *
 *   order_not_found    404
 *   order_not_payable  409  not pending, or past `expires_at`
 *   amount_mismatch    409  the provider's amount differs from `payment_orders.amount`
 *   order_busy         409  another open (state 1) transaction already holds the order
 */
final class ProviderTransactionException extends BillingException
{
    public const ORDER_NOT_FOUND = 'order_not_found';

    public const ORDER_NOT_PAYABLE = 'order_not_payable';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const ORDER_BUSY = 'order_busy';

    public static function orderNotFound(): self
    {
        return new self(self::ORDER_NOT_FOUND, 'Payment order not found.');
    }

    public static function orderNotPayable(string $status): self
    {
        return new self(self::ORDER_NOT_PAYABLE, "Payment order is not payable (status {$status}).");
    }

    public static function amountMismatch(int $expected, int $actual): self
    {
        return new self(self::AMOUNT_MISMATCH, "Amount {$actual} does not match the order amount {$expected}.");
    }

    public static function orderBusy(string $providerTransactionId): self
    {
        return new self(self::ORDER_BUSY, "Another transaction [{$providerTransactionId}] is already open on this order.");
    }

    public function httpStatus(): int
    {
        return $this->errorCode === self::ORDER_NOT_FOUND ? 404 : 409;
    }
}
