<?php

declare(strict_types=1);

namespace App\Domain\Learners\Support;

/**
 * The regions a learner can choose (docs/35 §2.2): the 14 first-level units of Uzbekistan by ISO 3166-2:UZ and `other` (outside
 * Uzbekistan). The list is `config/regions.php`, the names are `lang/{ru,uz}/regions.php`; `learners.region_code` stores the code,
 * NULL = not given ("I'd rather not say"). There is no CHECK in the database: this class is the whitelist.
 */
final class Regions
{
    /** Everybody outside Uzbekistan: one unit, no list of countries (docs/35 §2.2). */
    public const OTHER = 'other';

    /** The longest code (`learners.region_code` is varchar(8)). */
    public const MAX_LENGTH = 8;

    /** @return list<string> every code, the 14 units of Uzbekistan first, then `other` */
    public static function codes(): array
    {
        return array_values(array_map('strval', array_keys((array) config('regions.codes', []))));
    }

    /** @return list<string> the 14 units of Uzbekistan (without `other`) */
    public static function uzbekistan(): array
    {
        return array_values(array_filter(self::codes(), static fn (string $code): bool => $code !== self::OTHER));
    }

    public static function isValid(mixed $code): bool
    {
        return is_string($code) && in_array($code, self::codes(), true);
    }

    /** The name in the language of the learner; an unknown code is shown as it is. */
    public static function name(string $code, string $locale): string
    {
        if (! self::isValid($code)) {
            return $code;
        }

        $name = __('regions.'.$code, [], $locale);

        return is_string($name) ? $name : $code;
    }

    /** The English name (exports); an unknown code is shown as it is. */
    public static function englishName(string $code): string
    {
        $name = config('regions.codes.'.$code);

        return is_string($name) ? $name : $code;
    }

    /**
     * The 14 units of Uzbekistan in the order of the pickers for `$locale` (Tashkent City first, then by the alphabet of the
     * language; `config('regions.order')`). A code missing from the configured order is put after it, so no region is ever lost.
     *
     * @return list<string>
     */
    public static function ordered(string $locale): array
    {
        $order = config('regions.order.'.$locale) ?? config('regions.order.ru') ?? [];
        $order = array_values(array_filter(is_array($order) ? array_map('strval', $order) : [], self::isValid(...)));
        $order = array_values(array_intersect($order, self::uzbekistan()));

        return [...$order, ...array_values(array_diff(self::uzbekistan(), $order))];
    }
}
