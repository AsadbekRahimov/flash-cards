<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use App\Domain\Billing\Enums\SubscriptionProvider;

/**
 * The provider cannot do what was asked (docs/13 §2.1 BillingProvider).
 *
 *   provider_not_registered  409  no adapter bound for the provider (not part of this build / not configured)
 *   provider_capability      409  the adapter exists but does not renew / refund (Payme, Click)
 */
final class ProviderCapabilityException extends BillingException
{
    public const NOT_REGISTERED = 'provider_not_registered';

    public const UNSUPPORTED = 'provider_capability';

    public static function notRegistered(SubscriptionProvider $provider): self
    {
        return new self(self::NOT_REGISTERED, "No billing provider is registered for [{$provider->value}].");
    }

    public static function unsupported(SubscriptionProvider $provider, string $capability): self
    {
        return new self(self::UNSUPPORTED, "Provider [{$provider->value}] does not support {$capability}.");
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
