<?php

declare(strict_types=1);

use App\Domain\Practice\Support\EnglishLexicon;
use Tests\TestCase;

// Fixed before any test file is loaded, so top-level date code does not depend on the host's TZ.
date_default_timezone_set('UTC');

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * Recursively sort associative arrays by key so a jsonb column read back from
 * PostgreSQL (which reorders object keys: shorter first, then alphabetical)
 * can be compared with `toBe()` against the literal without depending on key
 * order. Values and key names are still compared strictly; list order is kept.
 *
 * @param  array<array-key, mixed>  $array
 * @return array<array-key, mixed>
 */
function ksorted(array $array): array
{
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = ksorted($value);
        }
    }

    if (! array_is_list($array)) {
        ksort($array);
    }

    return $array;
}

/**
 * The forms of the informal «sen» in an uz text that their shape gives away: the pronoun and the verbs of the 2nd person singular
 * (-san, -yapsan, -gansan, -ding, -sang, -gin). The English words an explanation quotes (`building`, `reading`) are not Uzbek. The
 * bare imperative (`qil`, `yubor`) and the possessive in -ng (`otang`) look like other words: the packs were read for them by hand.
 *
 * @return list<string>
 */
function uz_informal_forms(string $text): array
{
    preg_match_all("/[a-z][a-z']*/i", $text, $matches);
    $found = [];
    foreach ($matches[0] as $word) {
        $lower = strtolower(rtrim($word, "'"));
        if (EnglishLexicon::isWord($lower)) {
            continue;
        }
        if (preg_match('/\\A(?:sen|seni|senga|senda|sendan|sening|senlar)\\z/', $lower) === 1
            || preg_match('/(?:[ay]san|yapsan|gansan|ding|sang)(?:mi|u|chi|ku)?\\z|gin\\z/', $lower) === 1) {
            $found[] = $word;
        }
    }

    return $found;
}
