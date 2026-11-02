<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\ProPracticePromise;

/**
 * What the PUBLIC copy of Pro (the landing's plan card, the description of the Stars invoice) may promise beyond the plan
 * config. A line of that copy is a promise only while it is true for the person who reads it.
 *
 * "Practice (and battles) without a daily limit" (docs/18 §3, G1) is true only when practice is open to everybody
 * (`practice.access = all`, the public release): at `pilot` it is open to a few, at `off` (the production default) to nobody, and
 * a Pro that "removes the limit" of something nobody can use is a promise of a thing that is not there. Two flags decide, by the
 * rule `/me` hands the Mini App (`PracticeAccess::describe()`; the Mini App's `ProBenefits` picks the same two sentences):
 *
 *  - PRACTICE: open to everybody, and Free has a daily limit of solo sessions for Pro to lift (`practice.free_limits.sessions_per_day`;
 *    empty = no limit, as in PracticeLimits);
 *  - BATTLES: switched on (`practice.battle.enabled`) and Free has a daily limit of them (`practice.free_limits.battles_per_day`).
 *
 * Battles on and limited -> the line of both (a limit only on battles still gets it, like the Mini App's); only practice limited,
 * or battles off -> the line of practice alone (no battles are sold that do not exist); a limit only on battles while they are off
 * leaves nothing to lift -> no line at all. The choice is made here, in one place, and the services pick the text by it - never
 * the template.
 */
final class ProPromises
{
    public static function practice(): ProPracticePromise
    {
        // The same reading of the switch as PracticeAccess: anything but `all` (and `pilot`) is off, only `all` is for everybody.
        if (strtolower(trim((string) config('practice.access', 'off'))) !== 'all') {
            return ProPracticePromise::None;
        }

        $battles = (bool) config('practice.battle.enabled', false) && self::freeIsLimited('battles_per_day');

        return match (true) {
            $battles => ProPracticePromise::Both,
            self::freeIsLimited('sessions_per_day') => ProPracticePromise::Solo,
            default => ProPracticePromise::None,
        };
    }

    /** Whether the public copy says anything about practice at all. */
    public static function practiceUnlimited(): bool
    {
        return self::practice() !== ProPracticePromise::None;
    }

    /** A Free limit exists unless the value is empty (`null`, '' or false): `0` is a limit (only Pro may). */
    private static function freeIsLimited(string $kind): bool
    {
        $value = config("practice.free_limits.{$kind}");

        return ! ($value === null || $value === '' || $value === false);
    }
}
