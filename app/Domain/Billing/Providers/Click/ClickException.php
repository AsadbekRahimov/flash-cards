<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Click;

use RuntimeException;

/**
 * An error answer of the Click Merchant API (docs/13 §4.3): `error` + `error_note` in the HTTP 200
 * JSON body of Prepare / Complete. The HTTP status is not part of the protocol; the body is.
 *
 *    0  success
 *   -1  sign check failed        `sign_string` ≠ md5(...) with our secret, or `service_id` is not ours
 *   -2  incorrect amount         `amount` ≠ `payment_orders.amount` (or not a decimal with ≤ 2 places)
 *   -3  action not found         `action` is not the one of this endpoint (0 Prepare, 1 Complete)
 *   -4  already paid             the order is `paid` (a new Prepare / Complete after the money arrived)
 *   -5  user / order not found   `merchant_trans_id` matches no Click order
 *   -6  transaction not found    unknown `click_trans_id` / `merchant_prepare_id` in Complete
 *   -7  failed to update         we could not record the step (answered with HTTP 500 so Click retries)
 *   -8  error in request         a required parameter is missing or malformed
 *   -9  transaction canceled     the transaction (or its order) is canceled / expired
 */
final class ClickException extends RuntimeException
{
    public const SUCCESS = 0;

    public const SIGN_CHECK_FAILED = -1;

    public const INCORRECT_AMOUNT = -2;

    public const ACTION_NOT_FOUND = -3;

    public const ALREADY_PAID = -4;

    public const ORDER_NOT_FOUND = -5;

    public const TRANSACTION_NOT_FOUND = -6;

    public const UPDATE_FAILED = -7;

    public const REQUEST_ERROR = -8;

    public const TRANSACTION_CANCELED = -9;

    /** @var array<int, string> */
    public const NOTES = [
        self::SUCCESS => 'Success',
        self::SIGN_CHECK_FAILED => 'SIGN CHECK FAILED!',
        self::INCORRECT_AMOUNT => 'Incorrect parameter amount',
        self::ACTION_NOT_FOUND => 'Action not found',
        self::ALREADY_PAID => 'Already paid',
        self::ORDER_NOT_FOUND => 'User does not exist',
        self::TRANSACTION_NOT_FOUND => 'Transaction does not exist',
        self::UPDATE_FAILED => 'Failed to update user',
        self::REQUEST_ERROR => 'Error in request from click',
        self::TRANSACTION_CANCELED => 'Transaction cancelled',
    ];

    public function __construct(
        public readonly int $clickCode,
        ?string $detail = null,
    ) {
        parent::__construct(trim(sprintf('Click error %d: %s%s', $clickCode, self::note($clickCode), $detail === null ? '' : " ({$detail})")));
    }

    public static function signCheckFailed(string $detail): self
    {
        return new self(self::SIGN_CHECK_FAILED, $detail);
    }

    public static function incorrectAmount(string $detail): self
    {
        return new self(self::INCORRECT_AMOUNT, $detail);
    }

    public static function actionNotFound(int $action): self
    {
        return new self(self::ACTION_NOT_FOUND, "action {$action}");
    }

    public static function alreadyPaid(): self
    {
        return new self(self::ALREADY_PAID);
    }

    public static function orderNotFound(string $detail): self
    {
        return new self(self::ORDER_NOT_FOUND, $detail);
    }

    public static function transactionNotFound(string $detail): self
    {
        return new self(self::TRANSACTION_NOT_FOUND, $detail);
    }

    public static function updateFailed(string $detail): self
    {
        return new self(self::UPDATE_FAILED, $detail);
    }

    public static function requestError(string $detail): self
    {
        return new self(self::REQUEST_ERROR, $detail);
    }

    public static function transactionCanceled(string $detail): self
    {
        return new self(self::TRANSACTION_CANCELED, $detail);
    }

    public static function note(int $code): string
    {
        return self::NOTES[$code] ?? self::NOTES[self::UPDATE_FAILED];
    }
}
