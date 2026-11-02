<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

/**
 * What the PUBLIC copy of Pro (the landing's plan card, the description of the Stars invoice) may promise beyond the plan
 * config. A line of that copy is a promise only while it is true for the person who reads it.
 *
 * "Practice and battles without a daily limit" (docs/18 §3, G1) is true only when practice is open to everybody
 * (`practice.access = all`, the public release): at `pilot` it is open to a few, at `off` (the production default) to nobody, and
 * a Pro that "removes the limit" of something nobody can use is a promise of a thing that is not there. It also needs a
 * daily limit for Free to lift (`practice.free_limits.*`; empty = no limit, as in PracticeLimits). The choice is made here,
 * in one place, and the services pick the text by it - never the template.
 */
final class ProPromises
{
    public static function practiceUnlimited(): bool
    {
        // The same reading of the switch as PracticeAccess: anything but `all` (and `pilot`) is off, only `all` is for everybody.
        if (strtolower(trim((string) config('practice.access', 'off'))) !== 'all') {
            return false;
        }

        return self::freeIsLimited('sessions_per_day') || self::freeIsLimited('battles_per_day');
    }

    /** A Free limit exists unless the value is empty (`null`, '' or false): `0` is a limit (only Pro may). */
    private static function freeIsLimited(string $kind): bool
    {
        $value = config("practice.free_limits.{$kind}");

        return ! ($value === null || $value === '' || $value === false);
    }
}
