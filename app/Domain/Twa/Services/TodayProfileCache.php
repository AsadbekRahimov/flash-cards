<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * The part of `GET /today` that changes only with the learner's version (docs/19 K10): the "Pro ran out" banner of their own
 * subscription (`lapsed`, one indexed read of `subscriptions` for a Free learner), whether they are an active member of an active
 * Telegram group (`groups`: without one there is no caravan of a class to read, docs/18 §12.11) and whether they are an active member
 * of a group linked to a center, whatever the group's status (`assignments`: without one `AssignmentService::forLearner` can return
 * nothing, so the TWA does not ask `/assignments` - `assignments_possible` of `/today`, docs/04 A.2a). Two statements of the screen
 * become one read of the evicting store.
 *
 * Key `twa:today:v2:{learner}:n{version}` (v2: `assignments` joined the shape):
 *  - the version is the learner's cache version of {@see EntitlementResolver} (`entitlement:ver:{id}`, the non-evicting store), as the
 *    `/me` snapshot's ({@see MeProfileCache}): every write that changes the plan, a subscription (its grant, end, expiry run), a group
 *    membership (join, leave), the status of a group or its link to a center (relink, unlink, delete) bumps it, so the key every later reader looks at is a new one and the
 *    delete-then-put race cannot bring an old snapshot back. The version is read BEFORE the snapshot is computed (docs/12 §5.2);
 *  - the banner depends on the plan, and the plan changes by the clock without a bump (a trial that ran out before the expiry run):
 *    the snapshot keeps `until`, the end of the plan it was computed under, and is no snapshot once the clock passes it
 *    (as {@see MeProfileCache} does with {@see Entitlement::isCurrentAt()});
 *  - what the key does not cover ages out with the TTL (minutes, docs/19 §3 rule 5): the end of the 14-day window of the banner.
 *
 * Nothing of the learning is here: the counters, the sessions, the progress and the game change with every grade and every run.
 *
 * @phpstan-type TodaySnapshot array{lapsed: array{provider: string, ended_at: string}|null, groups: bool, assignments: bool, until: string|null}
 */
final class TodayProfileCache
{
    public const PREFIX = 'twa:today:v2:';

    /** Ten minutes, as the `/me` snapshot: the return to the Mini App after a session still hits. */
    public const TTL = 600;

    public static function key(Learner|int $learner, int $version): string
    {
        return self::PREFIX.($learner instanceof Learner ? $learner->id : $learner).':n'.$version;
    }

    /**
     * The snapshot of the learner under `$version` ({@see EntitlementResolver::version()}, read by the caller once for the request), or
     * null (none, not of the expected shape, or computed under a plan that has ended by `$now`).
     *
     * @return TodaySnapshot|null
     */
    public function get(Learner $learner, int $version, CarbonImmutable $now): ?array
    {
        $cached = PureCache::store()->get(self::key($learner, $version));

        if (! is_array($cached) || ! array_key_exists('lapsed', $cached) || ! is_bool($cached['groups'] ?? null) || ! is_bool($cached['assignments'] ?? null)
            || ! array_key_exists('until', $cached) || ! ($cached['until'] === null || is_string($cached['until']))
            || ! ($cached['lapsed'] === null || (is_array($cached['lapsed']) && isset($cached['lapsed']['provider'], $cached['lapsed']['ended_at'])))) {
            return null;
        }

        if ($cached['until'] !== null && ! CarbonImmutable::parse($cached['until'])->greaterThan($now)) {
            return null;
        }

        /** @var TodaySnapshot $cached */
        return $cached;
    }

    /**
     * Keeps the snapshot computed from the database under `$version` - the version the caller read BEFORE computing it: a bump that
     * landed meanwhile moved every later reader to a newer key, and this put goes to the dead one.
     *
     * @param  TodaySnapshot  $snapshot
     */
    public function put(Learner $learner, int $version, array $snapshot): void
    {
        PureCache::store()->put(self::key($learner, $version), $snapshot, self::TTL);
    }
}
