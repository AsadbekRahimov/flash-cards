<?php

declare(strict_types=1);

namespace App\Domain\Billing\Providers\Click;

/**
 * Click carries amounts as decimal UZS strings ("29000.00"); the ledger keeps tiyin integers.
 * Both directions are string arithmetic — no float ever touches the money (docs/13 §4.3:
 * "суммы с копейками → тийины точно").
 */
final class ClickAmount
{
    /** Tiyin of a decimal UZS string; null when the string is not a non-negative decimal with at most two places (extra places must be zeros). */
    public static function toTiyin(string $amount): ?int
    {
        if (preg_match('/^(\d{1,15})(?:\.(\d+))?$/', trim($amount), $m) !== 1) {
            return null;
        }

        $fraction = $m[2] ?? '';
        if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** The checkout / webhook form of a tiyin amount: UZS with exactly two decimal places. */
    public static function format(int $tiyin): string
    {
        return sprintf('%d.%02d', intdiv($tiyin, 100), $tiyin % 100);
    }
}
