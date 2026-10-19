<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * docs/I18N_UZ_REVIEW.md is what the native speaker reviews: every Uzbek string of the bot, the
 * reminders, the 2FA pages, the validation messages and the TWA must be listed there.
 */

/** @return list<string> */
function uzDocKeys(): array
{
    preg_match_all('/^\| `([^`]+)`/m', (string) file_get_contents(base_path('docs/I18N_UZ_REVIEW.md')), $m);

    return $m[1];
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

it('lists every resources/twa/src/i18n/uz.ts string in the review document', function (): void {
    $docKeys = uzDocKeys();
    preg_match_all("/^  '([a-zA-Z0-9_.]+)':/m", (string) file_get_contents(base_path('resources/twa/src/i18n/uz.ts')), $m);

    $missing = array_values(array_filter($m[1], fn (string $key): bool => ! uzDocCovers($docKeys, $key)));

    expect($m[1])->not->toBeEmpty()
        ->and($missing)->toBe([]);
});
