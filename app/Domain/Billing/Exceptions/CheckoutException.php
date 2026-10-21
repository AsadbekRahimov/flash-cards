<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DateTimeInterface;

/**
 * Why a checkout could not be started (CheckoutService::create, docs/13 §2.1):
 *
 *   price_not_configured    409  no price for (plan/tier, period, currency), or above the provider's cap
 *   provider_disabled       409  provider not registered or `billing.providers.<key>.enabled` is false
 *   provider_not_supported  422  provider does not serve this subscriber type / currency (Stars for an organization)
 *   checkout_conflict       409  a live auto-renewing (Stars) subscription still renews: any new order of any provider
 *                                (the same one included) would give a second source of renewal — cancel it first
 *   already_perpetual       409  the learner already has Pro without an end date (a manual grant): nothing to buy
 *   subscribe_too_early     409  a Stars subscription was ordered while a prepaid period ends in more than
 *                                `billing.stars.subscribe_window_days` days: Telegram would start its own 30 days at once
 *   tier_change_requires_support 409  an organization with a live subscription ordered a DIFFERENT tier: only a
 *                                renewal of the current tier is sold online, a change goes through the platform
 *   too_many_pending        429  the subscriber already has `checkout.max_pending_per_subscriber` pending orders
 *   offer_not_applicable    409  the offer is redeemed / expired / someone else's / for another plan or period
 *   offer_busy              409  the offer is on a pending order that is being paid right now (open provider
 *                                transaction), or a parallel checkout keeps moving it — retry in a moment
 *   invalid_period          422  period is not one of `billing.periods`
 *   invalid_tier            422  organization checkout without a sellable tier
 */
final class CheckoutException extends BillingException
{
    public const PRICE_NOT_CONFIGURED = 'price_not_configured';

    public const PROVIDER_DISABLED = 'provider_disabled';

    public const PROVIDER_NOT_SUPPORTED = 'provider_not_supported';

    public const CONFLICT = 'checkout_conflict';

    public const ALREADY_PERPETUAL = 'already_perpetual';

    public const SUBSCRIBE_TOO_EARLY = 'subscribe_too_early';

    public const TIER_CHANGE_REQUIRES_SUPPORT = 'tier_change_requires_support';

    public const TOO_MANY_PENDING = 'too_many_pending';

    public const OFFER_NOT_APPLICABLE = 'offer_not_applicable';

    public const OFFER_BUSY = 'offer_busy';

    public const INVALID_PERIOD = 'invalid_period';

    public const INVALID_TIER = 'invalid_tier';

    public static function priceNotConfigured(string $what): self
    {
        return new self(self::PRICE_NOT_CONFIGURED, "No price is configured for {$what}.");
    }

    public static function providerDisabled(string $provider): self
    {
        return new self(self::PROVIDER_DISABLED, "Payment provider [{$provider}] is not available.");
    }

    public static function providerNotSupported(string $provider, string $subscriberType, string $currency): self
    {
        return new self(self::PROVIDER_NOT_SUPPORTED, "Payment provider [{$provider}] does not serve {$subscriberType} in {$currency}.");
    }

    public static function conflict(string $liveProvider): self
    {
        return new self(self::CONFLICT, "A live auto-renewing [{$liveProvider}] subscription exists; cancel its renewal before ordering again.");
    }

    public static function alreadyPerpetual(): self
    {
        return new self(self::ALREADY_PERPETUAL, 'Pro is already active with no end date; there is nothing to buy.');
    }

    public static function subscribeTooEarly(DateTimeInterface $until, int $windowDays): self
    {
        return new self(self::SUBSCRIBE_TOO_EARLY, "Pro is already active until {$until->format('Y-m-d')}; a Stars subscription can be started within {$windowDays} days before the end.");
    }

    public static function tierChangeRequiresSupport(string $liveTier, string $orderedTier): self
    {
        return new self(self::TIER_CHANGE_REQUIRES_SUPPORT, "The subscription is on tier [{$liveTier}]; tier [{$orderedTier}] cannot be bought online while it is live — contact support to change the tariff.");
    }

    public static function tooManyPending(int $max): self
    {
        return new self(self::TOO_MANY_PENDING, "Too many pending orders (max {$max}); pay or wait for one to expire.");
    }

    public static function offerNotApplicable(): self
    {
        return new self(self::OFFER_NOT_APPLICABLE, 'The offer cannot be applied to this purchase.');
    }

    public static function offerBusy(): self
    {
        return new self(self::OFFER_BUSY, 'The offer is on an order that is being paid right now; finish that payment or try again shortly.');
    }

    public static function invalidPeriod(string $period): self
    {
        return new self(self::INVALID_PERIOD, "Unknown billing period [{$period}].");
    }

    public static function invalidTier(?string $tier): self
    {
        return new self(self::INVALID_TIER, $tier === null ? 'An organization checkout requires a tier.' : "Unknown or unsellable tier [{$tier}].");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::PROVIDER_NOT_SUPPORTED, self::INVALID_PERIOD, self::INVALID_TIER => 422,
            self::TOO_MANY_PENDING => 429,
            default => 409,
        };
    }
}
