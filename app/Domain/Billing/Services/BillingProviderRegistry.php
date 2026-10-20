<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\BillingProvider;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\ProviderCapabilityException;

/**
 * Map of the bound payment providers (docs/13 §2.2). A singleton: the real adapters register
 * themselves in AppServiceProvider (2A.3–2A.5), tests register FakeBillingProvider. Whether a
 * registered provider is *sellable* is decided by `billing.providers.<key>.enabled`, so an
 * adapter can be deployed before the owner switches it on.
 */
final class BillingProviderRegistry
{
    /** @var array<string, BillingProvider> */
    private array $providers = [];

    public function register(BillingProvider $provider): void
    {
        $this->providers[$provider->key()->value] = $provider;
    }

    public function has(SubscriptionProvider $provider): bool
    {
        return isset($this->providers[$provider->value]);
    }

    /** @throws ProviderCapabilityException provider_not_registered */
    public function for(SubscriptionProvider $provider): BillingProvider
    {
        return $this->providers[$provider->value] ?? throw ProviderCapabilityException::notRegistered($provider);
    }

    /** Registered and switched on in config. */
    public function isEnabled(SubscriptionProvider $provider): bool
    {
        return $this->has($provider) && (bool) config("billing.providers.{$provider->value}.enabled", false);
    }

    /** @return list<SubscriptionProvider> */
    public function enabled(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $key): ?SubscriptionProvider => SubscriptionProvider::tryFrom($key), array_keys($this->providers)),
            fn (?SubscriptionProvider $p): bool => $p !== null && $this->isEnabled($p),
        ));
    }
}
