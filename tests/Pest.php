<?php

declare(strict_types=1);

use Tests\TestCase;

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
