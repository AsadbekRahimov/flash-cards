<?php

declare(strict_types=1);

use App\Domain\Game\Support\CityNames;
use Illuminate\Support\Arr;

/*
 * docs/18 §12.0, §12.15: the city is a module, its name comes from the static list of the Silk Road by the number of the module.
 */

it('maps the number of a module to the lang key of its city, "City N" past the list', function (): void {
    expect(CityNames::key(1))->toBe('game.cities.1')
        ->and(CityNames::key(24))->toBe('game.cities.24')
        ->and(CityNames::key(25))->toBe('game.city_fallback')
        ->and(CityNames::key(0))->toBe('game.city_fallback')
        ->and(CityNames::key(-3))->toBe('game.city_fallback');
});

it('names the cities in Russian and in Uzbek', function (): void {
    expect(CityNames::name(3, 'ru'))->toBe('Самарканд')
        ->and(CityNames::name(3, 'uz'))->toBe('Samarqand')
        ->and(CityNames::name(2, 'uz'))->toBe("Qo'qon")
        ->and(CityNames::name(25, 'ru'))->toBe('Город 25')
        ->and(CityNames::name(30, 'uz'))->toBe('30-shahar');

    app()->setLocale('uz');
    expect(CityNames::name(4))->toBe('Buxoro');
});

it('has a name for every city in both languages, the same set of keys, no empty names and no duplicates', function (): void {
    $ru = require lang_path('ru/game.php');
    $uz = require lang_path('uz/game.php');

    expect(array_keys($ru['cities']))->toBe(range(1, CityNames::COUNT))
        ->and(array_keys($uz['cities']))->toBe(range(1, CityNames::COUNT))
        ->and(array_keys(Arr::dot($uz)))->toBe(array_keys(Arr::dot($ru)))
        ->and(array_unique($ru['cities']))->toHaveCount(CityNames::COUNT)
        ->and(array_unique($uz['cities']))->toHaveCount(CityNames::COUNT)
        ->and($ru['city_fallback'])->toContain(':number')
        ->and($uz['city_fallback'])->toContain(':number');

    foreach ([...$ru['cities'], ...$uz['cities']] as $name) {
        expect(trim($name))->not->toBe('');
    }
});

it('writes the Uzbek names in Latin with the ASCII apostrophe', function (): void {
    $uz = require lang_path('uz/game.php');

    foreach ([...$uz['cities'], $uz['city_fallback']] as $name) {
        expect($name)->not->toMatch('/[^\x20-\x7E]/u'); // no Cyrillic, no typographic apostrophe: ASCII only
    }
});
