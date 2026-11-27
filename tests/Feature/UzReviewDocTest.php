<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * docs/I18N_UZ_REVIEW.md is what the native speaker reviews: every Uzbek string of the bot, the
 * reminders, the 2FA pages, the validation messages and the TWA must be listed there.
 */

/**
 * The keys of the rows of the document; a row of a numbered range - `streak.week.day.1…7` (or `1...7`) - stands for every key of it.
 *
 * @return list<string>
 */
function uzDocKeys(): array
{
    preg_match_all('/^\| `([^`]+)`/m', (string) file_get_contents(base_path('docs/I18N_UZ_REVIEW.md')), $m);

    $keys = [];
    foreach ($m[1] as $key) {
        $keys[] = $key;
        if (preg_match('/^(.+\.)(\d+)(?:…|\.\.\.)(\d+)$/u', $key, $range) === 1 && (int) $range[2] <= (int) $range[3]) {
            foreach (range((int) $range[2], (int) $range[3]) as $n) {
                $keys[] = $range[1].$n;
            }
        }
    }

    return $keys;
}

/** A key is covered by `key`, by its plural forms `key.one` / `key.other`, or by a `key.one/other` row. */
function uzDocCovers(array $docKeys, string $key): bool
{
    foreach ([$key, "{$key}.one", "{$key}.other", "{$key}.option.one/other", "{$key}.one/other"] as $candidate) {
        if (in_array($candidate, $docKeys, true)) {
            return true;
        }
    }

    return false;
}

it('lists every lang/uz/*.php string in the review document', function (): void {
    $docKeys = uzDocKeys();
    $missing = [];

    foreach (glob(lang_path('uz/*.php')) ?: [] as $file) {
        foreach (array_keys(Arr::dot(require $file)) as $key) {
            $full = basename($file, '.php').'.'.$key;
            if (str_starts_with($full, 'validation.custom.') || uzDocCovers($docKeys, $full)) {
                continue;
            }
            $missing[] = $full;
        }
    }

    expect($missing)->toBe([]);
});

/** @return list<string> resources/twa/src/i18n/uz.ts and the Uzbek dictionaries of the lazy screens, `i18n/<screen>/uz.ts` */
function uzTwaDictionaries(): array
{
    // Not base_path(): the dataset below is listed before the application boots.
    $i18n = dirname(__DIR__, 2).'/resources/twa/src/i18n';

    return [$i18n.'/uz.ts', ...(glob($i18n.'/*/uz.ts') ?: [])];
}

it('lists every Uzbek string of the TWA (uz.ts and the lazy i18n/*/uz.ts) in the review document', function (string $file): void {
    $docKeys = uzDocKeys();
    preg_match_all("/^  '([a-zA-Z0-9_.]+)':/m", (string) file_get_contents($file), $m);

    $missing = array_values(array_filter($m[1], fn (string $key): bool => ! uzDocCovers($docKeys, $key)));

    expect($m[1])->not->toBeEmpty()
        ->and($missing)->toBe([]);
})->with(fn (): array => array_combine(
    array_map(static fn (string $file): string => substr($file, strlen(dirname(__DIR__, 2)) + 1), uzTwaDictionaries()),
    array_map(static fn (string $file): array => [$file], uzTwaDictionaries()),
));

it('reads the lazy dictionaries and the numbered ranges of the document', function (): void {
    expect(uzTwaDictionaries())->toContain(dirname(__DIR__, 2).'/resources/twa/src/i18n/streak/uz.ts')
        ->and(uzDocCovers(uzDocKeys(), 'streak.week.day.7'))->toBeTrue()
        ->and(uzDocCovers(uzDocKeys(), 'streak.week.day.8'))->toBeFalse();
});
