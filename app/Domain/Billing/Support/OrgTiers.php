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

    /**
     * Price in minor units; null when the tier is not sold for that period / currency. An explicit configured
     * price wins; otherwise the `year` is derived from the `month` price: month x 12 x (1 - discount / 100),
     * rounded to whole sums (100 tiyin) — "Реализация 2B.9".
     */
    public static function price(string $tier, string $period, string $currency): ?int
    {
        $price = config("billing.org_tiers.{$tier}.prices.{$period}.{$currency}");

        if (is_numeric($price) && (int) $price > 0) {
            return (int) $price;
        }

        if ($period !== 'year') {
            return null;
        }

        $month = config("billing.org_tiers.{$tier}.prices.month.{$currency}");

        if (! is_numeric($month) || (int) $month <= 0) {
            return null;
        }

        return self::yearFromMonth((int) $month);
    }

    /** What the year saves versus 12 months of the same tier, minor units; 0 when nothing (or not sold). */
    public static function yearSavings(string $tier, string $currency): int
    {
        $month = self::price($tier, 'month', $currency);
        $year = self::price($tier, 'year', $currency);

        return $month === null || $year === null ? 0 : max(0, $month * 12 - $year);
    }

    /** The only place that bounds the discount: 0..99 (100 is clamped to 99, the year is never free). */
    private static function yearFromMonth(int $month): int
    {
        $discount = max(0, min(99, (int) config('billing.org_year_discount_percent', 0)));
        $year = (int) (round($month * 12 * (100 - $discount) / 100 / 100) * 100);

        return max(100, $year);
    }
}
