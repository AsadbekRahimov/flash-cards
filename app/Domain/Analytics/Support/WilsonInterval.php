<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

/**
 * The Wilson score interval of a share `count / of` (audit 2026-11-18, «Как оценивать успех продукта»: on small traffic show the
 * numerator, the denominator and the uncertainty). Unlike `p ± z·√(p(1−p)/n)` it never leaves 0-100 % and does not collapse to
 * a point at 0 of n or n of n, which is exactly the case of a small cohort. Pure: no I/O, no state.
 *
 * The admin pages show it only under {@see SMALL_BASE} learners ({@see forSmallBase()}): above that the 95 % interval is a few
 * points wide and the extra line only adds noise.
 */
final class WilsonInterval
{
    /** The two-sided 95 % quantile of the normal distribution. */
    public const Z95 = 1.959963984540054;

    /** Below this denominator a share is shown with its interval. */
    public const SMALL_BASE = 200;

    /**
     * The interval in percent, rounded to one decimal; null when the base is empty (no share to speak of) or the numbers make
     * no sense (a negative count, more hits than the base).
     *
     * @return array{low: float, high: float}|null
     */
    public static function of(int $count, int $of, float $z = self::Z95): ?array
    {
        if ($of <= 0 || $count < 0 || $count > $of) {
            return null;
        }

        $p = $count / $of;
        $z2 = $z * $z;
        $denominator = 1 + $z2 / $of;
        $centre = ($p + $z2 / (2 * $of)) / $denominator;
        $half = $z * sqrt($p * (1 - $p) / $of + $z2 / (4 * $of * $of)) / $denominator;

        return [
            'low' => round(max(0.0, $centre - $half) * 100, 1),
            'high' => round(min(1.0, $centre + $half) * 100, 1),
        ];
    }

    /**
     * The 95 % interval only when the base is below {@see SMALL_BASE}; null for a large or an empty base.
     *
     * @return array{low: float, high: float}|null
     */
    public static function forSmallBase(int $count, int $of): ?array
    {
        return $of < self::SMALL_BASE ? self::of($count, $of) : null;
    }
}
