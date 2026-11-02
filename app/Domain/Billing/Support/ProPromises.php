<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\ProPracticePromise;

/**
 * What the copy of Pro (the landing's plan card, the description of the Stars invoice, the "what Pro gives" list of the Mini App)
 * may promise about practice beyond the plan config. A line of that copy is a promise only while it is true for the person who
 * reads it.
 *
 * "Practice (and battles) without a daily limit" (docs/18 §3, G1) is one rule with one place to live, this class; every reader
 * only says whether practice is OPEN to the person who reads, and the rest is decided here:
 *
 *  - the PUBLIC copy ({@see publicPractice()}: the landing, the Stars invoice) reads for nobody in particular, so practice is open
 *    there only when it is open to everybody (`practice.access = all`): at `pilot` it is open to a few, at `off` (the production
 *    default) to nobody, and a Pro that "removes the limit" of something nobody can use is a promise of a thing that is not there;
 *  - the Mini App reads for a learner: `/me` sends `practice.pro_promise` (`PracticeAccess::describe()`), computed with `open` =
 *    whether the entries of practice are shown to that learner (`PracticeAccessState::showsEntries()`: everybody at `all`, the
 *    pilot's learners at `pilot`, a Free learner at `plan = pro` with the Pro chip). The Mini App draws the answer and decides nothing.
 *
 * Given that practice is open to the reader:
 *
 *  1. `practice.plan = pro` (Free has no practice at all, Pro opens all of it): there is no Free limit to lift, practice itself is
 *     what Pro gives - battles on -> both, battles off -> practice alone;
 *  2. otherwise Free has a daily limit for Pro to lift ("limited" as in PracticeLimits: empty = no limit, `0` = only Pro creates):
 *     battles on and limited -> both (a limit only on battles still gets that line); a limit on solo sessions -> practice alone
 *     (also when the battles are off: no battles are sold that do not exist); nothing limited, or a limit only on battles
 *     that are off -> nothing to lift, no line at all.
 *
 * The choice is made here, in one place, and the services pick the text by it - never the template.
 */
final class ProPromises
{
    /** The promise of the public copy: practice is open there only when it is open to everybody. */
    public static function publicPractice(): ProPracticePromise
    {
        return self::practice(open: self::normalized(config('practice.access', 'off')) === 'all');
    }

    /**
     * @param  bool  $open  whether practice is open to the one who reads (see the class docblock); when it is not, there is nothing to promise
     */
    public static function practice(bool $open): ProPracticePromise
    {
        if (! $open) {
            return ProPracticePromise::None;
        }

        $battles = (bool) config('practice.battle.enabled', false);

        // The same reading of the switch as PracticeAccess::resolve().
        if (self::normalized(config('practice.plan', 'all')) === 'pro') {
            return $battles ? ProPracticePromise::Both : ProPracticePromise::Solo;
        }

        return match (true) {
            $battles && self::freeIsLimited('battles_per_day') => ProPracticePromise::Both,
            self::freeIsLimited('sessions_per_day') => ProPracticePromise::Solo,
            default => ProPracticePromise::None,
        };
    }

    /** A Free limit exists unless the value is empty (`null`, '' or false): `0` is a limit (only Pro may). */
    private static function freeIsLimited(string $kind): bool
    {
        $value = config("practice.free_limits.{$kind}");

        return ! ($value === null || $value === '' || $value === false);
    }

    /** A text switch of `config/practice.php` as PracticeAccess reads it: case and spaces do not matter. */
    private static function normalized(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
