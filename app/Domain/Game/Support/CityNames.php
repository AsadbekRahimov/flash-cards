<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

/**
 * The names of the cities of the map (docs/18 §12.0, §12.15): a city is a module of a course, its name comes from the static list
 * of the Silk Road in `lang/{ru,uz}/game.php` by the NUMBER of the module (`stages.number`), whatever the course is about. Past the
 * list (24 cities) the name is "City N". The owner edits the lang files with a release; nothing is cached or stored.
 */
final class CityNames
{
    /** How many cities have a name of their own (`game.cities.1` ... `game.cities.24`). */
    public const COUNT = 24;

    /** The lang key of the name of the city of the module `$number`. */
    public static function key(int $number): string
    {
        return $number >= 1 && $number <= self::COUNT ? "game.cities.{$number}" : 'game.city_fallback';
    }

    /** The name of the city in the locale (the current one when null). */
    public static function name(int $number, ?string $locale = null): string
    {
        $key = self::key($number);

        return $key === 'game.city_fallback'
            ? __($key, ['number' => $number], $locale)
            : __($key, [], $locale);
    }
}
