<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

/**
 * Human-readable prices for the bot (`lang/{ru,uz}/billing.php`, `billing.currency.*`). Amounts are
 * minor units (docs/13 §1.2): whole Stars for `XTR`, tiyin for `UZS` (100 tiyin = 1 sum).
 */
final class PriceFormatter
{
    public static function format(int $minorUnits, string $currency, string $locale): string
    {
        $major = $currency === 'UZS' ? intdiv($minorUnits, 100) : $minorUnits;
        $key = "billing.currency.{$currency}";

        return __($key, ['amount' => number_format($major, 0, '', ' ')], $locale);
    }
}
