<?php

declare(strict_types=1);

namespace App\Domain\Game\Support;

use Illuminate\Support\Facades\Lang;

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

    /**
     * The name for "I got to ..." (Russian "до Самарканда"): the genitive when the language has it (`game.cities_gen.N`, `city_fallback_gen`),
     * else the name itself - Uzbek takes the plain name.
     */
    public static function toward(int $number, ?string $locale = null): string
    {
        $key = $number >= 1 && $number <= self::COUNT ? "game.cities_gen.{$number}" : 'game.city_fallback_gen';

        // Without the fallback language: a missing Uzbek form must not turn into the Russian one.
        return Lang::has($key, $locale, false) ? __($key, ['number' => $number], $locale) : self::name($number, $locale);
    }
}
