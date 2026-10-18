<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * Why a promo code could not be redeemed (PromoCodeService::redeem).
 *
 * Codes and the HTTP statuses the TWA endpoint should answer with (docs/12 §3.8):
 *   promo_not_found        404  unknown or deactivated code (the two are not distinguished on purpose)
 *   promo_expired          410  `expires_at` has passed
 *   promo_exhausted        409  `max_redemptions` reached
 *   promo_already_redeemed 409  this learner has already used this code
 *   promo_not_applicable   409  the learner already has a perpetual personal subscription — nothing to extend
 */
final class PromoCodeException extends BillingException
{
    public const NOT_FOUND = 'promo_not_found';

    public const EXPIRED = 'promo_expired';

    public const EXHAUSTED = 'promo_exhausted';

    public const ALREADY_REDEEMED = 'promo_already_redeemed';

    public const NOT_APPLICABLE = 'promo_not_applicable';

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Promo code not found.');
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED, 'Promo code has expired.');
    }

    public static function exhausted(): self
    {
        return new self(self::EXHAUSTED, 'Promo code has no redemptions left.');
    }

    public static function alreadyRedeemed(): self
    {
        return new self(self::ALREADY_REDEEMED, 'Promo code was already redeemed by this learner.');
    }

    public static function notApplicable(): self
    {
        return new self(self::NOT_APPLICABLE, 'The learner already has a perpetual subscription; the promo code would add nothing.');
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND => 404,
            self::EXPIRED => 410,
            default => 409,
        };
    }
}
