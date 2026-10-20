<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why an admin could not annul an offer (OfferService::annul, docs/13 §2.1). Nothing is changed.
 *
 *   forbidden   403  the actor is not an active admin
 *   not_open    409  the offer is already redeemed or has expired
 */
final class OfferException extends BillingException
{
    public const FORBIDDEN = 'forbidden';

    public const NOT_OPEN = 'not_open';

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'Only an active administrator can annul an offer.');
    }

    public static function notOpen(): self
    {
        return new self(self::NOT_OPEN, 'The offer is already redeemed or expired.');
    }

    public function httpStatus(): int
    {
        return $this->errorCode === self::FORBIDDEN ? 403 : 409;
    }
}
