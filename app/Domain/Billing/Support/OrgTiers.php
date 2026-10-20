<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

/**
 * Organization tariffs from config('billing.org_tiers') (docs/13 §0, §6.1): `plan` stays `pro`,
 * the tier decides the seats and the price. A tier without prices (center_l) is activated by a
 * superadmin only.
 */
final class OrgTiers
{
    /** @return list<string> */
    public static function keys(): array
    {
        /** @var array<string, mixed> $tiers */
        $tiers = (array) config('billing.org_tiers', []);

        return array_map(static fn (string|int $k): string => (string) $k, array_keys($tiers));
    }

    public static function exists(string $tier): bool
    {
        return is_array(config("billing.org_tiers.{$tier}"));
    }

    /** Seats of the tier; null = unlimited (or unknown tier). */
    public static function seats(string $tier): ?int
    {
        $seats = config("billing.org_tiers.{$tier}.seats");

        return $seats === null ? null : max(1, (int) $seats);
    }

    /** Price in minor units; null when the tier is not sold for that period / currency. */
    public static function price(string $tier, string $period, string $currency): ?int
    {
        $price = config("billing.org_tiers.{$tier}.prices.{$period}.{$currency}");

        return is_numeric($price) && (int) $price > 0 ? (int) $price : null;
    }
}
