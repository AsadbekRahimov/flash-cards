<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Why a billing action of the `/org` cabinet was refused (docs/13 §6.5 OrgBillingService). Checkout
 * refusals (`price_not_configured`, `provider_disabled`, `provider_not_supported` ...) stay
 * `CheckoutException`s of the billing domain.
 *
 *   not_owner                 403  billing is the owner's: a teacher / a stranger gets nothing
 *   tier_not_purchasable      422  the tier has no price for the period (center_l): activated by the platform only
 *   invoice_not_found         404  the order is not an order of this organization
 *   invoice_not_configured    409  no address to send invoice requests to (`billing.invoice.request_email`)
 *   invoice_request_throttled 429  too many requests from this organization (one per 10 minutes, a few per day)
 *   organization_suspended    409  a suspended center cannot place an order
 */
final class OrgBillingException extends RuntimeException
{
    public const NOT_OWNER = 'not_owner';

    public const TIER_NOT_PURCHASABLE = 'tier_not_purchasable';

    public const INVOICE_NOT_FOUND = 'invoice_not_found';

    public const INVOICE_NOT_CONFIGURED = 'invoice_not_configured';

    public const INVOICE_REQUEST_THROTTLED = 'invoice_request_throttled';

    public const ORGANIZATION_SUSPENDED = 'organization_suspended';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notOwner(int $organizationId): self
    {
        return new self(self::NOT_OWNER, "The user is not an owner of organization #{$organizationId}.");
    }

    public static function tierNotPurchasable(string $tier, string $period): self
    {
        return new self(self::TIER_NOT_PURCHASABLE, "Tier [{$tier}] is not sold for the period [{$period}]; it is activated by the platform.");
    }

    public static function invoiceNotFound(int $orderId, int $organizationId): self
    {
        return new self(self::INVOICE_NOT_FOUND, "Order #{$orderId} is not an order of organization #{$organizationId}.");
    }

    public static function invoiceNotConfigured(): self
    {
        return new self(self::INVOICE_NOT_CONFIGURED, 'No address is configured for invoice requests.');
    }

    public static function organizationSuspended(int $organizationId): self
    {
        return new self(self::ORGANIZATION_SUSPENDED, "Organization #{$organizationId} is suspended and cannot place an order.");
    }

    public static function invoiceRequestThrottled(): self
    {
        return new self(self::INVOICE_REQUEST_THROTTLED, 'An identical invoice request was sent a moment ago.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_OWNER => 403,
            self::TIER_NOT_PURCHASABLE => 422,
            self::INVOICE_NOT_FOUND => 404,
            self::INVOICE_REQUEST_THROTTLED => 429,
            default => 409,
        };
    }
}
