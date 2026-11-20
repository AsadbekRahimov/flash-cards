<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

/**
 * Billing periods from config (docs/13 §4.4, §5): `billing.periods` (key => days) is the single list of the periods of a
 * learner's Pro (`payment_orders.period`); `billing.org_periods` is the part of it organizations may buy (the quarter is a
 * learner-only period). A period is sold in a currency only while `billing.prices.<plan>.<period>` has a price > 0 in it
 * (CheckoutService): 0 and a missing key are the same "not for sale in that currency" (409 `price_not_configured`).
 */
final class BillingPeriods
{
    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const YEAR = 'year';

    /** Days of a month in the saving arithmetic: a period is worth `round(days / 30)` months. */
    private const MONTH_DAYS = 30;

    /** Currencies a saving is read in; the first one both periods are priced in wins (as in the Mini App: sums, then Stars). */
    private const SAVING_CURRENCIES = ['UZS', 'XTR'];

    /** @return list<string> every period of `billing.periods`, in the order of the config */
    public static function keys(): array
    {
        /** @var array<string, mixed> $periods */
        $periods = (array) config('billing.periods', []);

        return array_map(static fn (string|int $k): string => (string) $k, array_keys($periods));
    }

    /** @return list<string> the periods an organization may buy: `billing.org_periods` that exist in `billing.periods` */
    public static function forOrganizations(): array
    {
        $allowed = array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : '', (array) config('billing.org_periods', []));

        return array_values(array_filter(self::keys(), static fn (string $period): bool => in_array($period, $allowed, true)));
    }

    public static function exists(string $period): bool
    {
        return is_numeric(config("billing.periods.{$period}"));
    }

    /** Days of a period; an unknown period = 30 days (what an old row falls back to). */
    public static function days(string $period): int
    {
        return max(1, (int) config("billing.periods.{$period}", self::MONTH_DAYS));
    }

    /** Whole months a period is worth in the saving arithmetic: month 1, quarter 3, year 12. */
    public static function months(string $period): int
    {
        return max(1, (int) round(self::days($period) / self::MONTH_DAYS));
    }

    /**
     * How much cheaper the period is than the same time in months, whole percent: `round((1 - price / (months x month price)) x 100)`
     * — the rule the Mini App applied to the year (docs/14 §6.6). Both prices are list prices (before the offer) in one currency:
     * UZS, else XTR. 0 for the month itself, when no currency prices both, or when the period saves nothing.
     *
     * @param  array<string, int>  $listPrices  of the period
     * @param  array<string, int>  $monthListPrices  of the month of the same plan / tier
     */
    public static function savingPct(string $period, array $listPrices, array $monthListPrices): int
    {
        $months = self::months($period);

        if ($period === self::MONTH || $months <= 1) {
            return 0;
        }

        foreach (self::SAVING_CURRENCIES as $currency) {
            $price = $listPrices[$currency] ?? 0;
            $month = $monthListPrices[$currency] ?? 0;

            if ($price > 0 && $month > 0) {
                return max(0, (int) round((1 - $price / ($months * $month)) * 100));
            }
        }

        return 0;
    }
}
