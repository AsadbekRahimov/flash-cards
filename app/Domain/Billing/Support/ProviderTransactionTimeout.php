<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SubscriptionProvider;
use Carbon\CarbonImmutable;

/**
 * How long an open (`state = 1`) provider transaction keeps its order "being paid"
 * (docs/13 §4.2, §4.3, "Реализация 2B.8"): `billing.providers.<key>.transaction_timeout_ms`.
 *
 *   payme : 12 h — Payme's own lifetime of an unpaid CreateTransaction (then it cancels with reason 4);
 *   click : 30 min by default — Prepare -> debit -> Complete takes seconds, the margin covers Click's
 *           retries of Complete after an error on our side;
 *   stars : no `provider_transactions` rows, nothing to time out.
 *
 * Shared by OfferService::isBeingPaid (offer reservation), CheckoutService::expirePending and the
 * `PaymentOrder::withoutOpenProviderTransaction` scope so the three never disagree.
 */
final class ProviderTransactionTimeout
{
    private const DEFAULT_MS = [
        'payme' => 43_200_000,
        'click' => 1_800_000,
    ];

    /** Timeout in milliseconds for the provider; null when it has no provider transactions (Stars). */
    public static function ms(SubscriptionProvider|string $provider): ?int
    {
        $key = $provider instanceof SubscriptionProvider ? $provider->value : $provider;
        $configured = config("billing.providers.{$key}.transaction_timeout_ms");
        $ms = is_numeric($configured) ? (int) $configured : (self::DEFAULT_MS[$key] ?? null);

        return $ms === null ? null : max(1, $ms);
    }

    /**
     * The instant before which an open transaction of the provider is considered dead at `$now`;
     * null when the provider has no transactions.
     */
    public static function openSince(SubscriptionProvider|string $provider, CarbonImmutable $now): ?CarbonImmutable
    {
        $ms = self::ms($provider);

        return $ms === null ? null : $now->subMilliseconds($ms);
    }

    /**
     * The cut-off per provider that has one (`payme`, `click`), for a query over mixed orders.
     *
     * @return array<string, CarbonImmutable>
     */
    public static function openSinceByProvider(CarbonImmutable $now): array
    {
        $cutoffs = [];

        foreach (SubscriptionProvider::cases() as $provider) {
            $since = self::openSince($provider, $now);
            if ($since !== null) {
                $cutoffs[$provider->value] = $since;
            }
        }

        return $cutoffs;
    }
}
