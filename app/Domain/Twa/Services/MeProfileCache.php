<?php

declare(strict_types=1);

namespace App\Domain\Twa\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The part of `GET /me` that does not change from one answer to the next (docs/19 K9): the plan, the learner's Telegram
 * groups, the renewal and the "Pro ran out" banner of their own subscription, the active course of the settings. Four
 * statements of the profile become one read of the evicting store.
 *
 * Key `twa:me:v1:{learner}:{locale}:c{active course id}:n{version}`:
 *  - the version is the learner's cache version of {@see EntitlementResolver} (`entitlement:ver:{id}`, the non-evicting
 *    store): every write that changes the plan, a group or organization membership, a subscription or its renewal
 *    reminder bumps it, so the key every later reader looks at is a new one and the delete-then-put race cannot bring an
 *    old snapshot back. The version is read BEFORE the snapshot is computed (docs/12 §5.2);
 *  - the locale and the active course are in the key: the title of the course is localized and `POST /me/course` and the
 *    onboarding change the course without a bump;
 *  - what the key does not cover ages out with the TTL (minutes, docs/19 §3 rule 5): the title or the status of a course
 *    an admin edited, the end of the 14-day window of the banner, a renamed group;
 *  - the plan inside the snapshot is checked against its own end ({@see Entitlement::isCurrentAt()}), as the entitlement
 *    cache does: a snapshot whose Pro ran out is recomputed, whatever the TTL.
 *
 * `paywall`, `settings` (but the course), `practice`, `game` and `stats` are not here: they come from the config, the
 * learner's row and counts that change with every grade.
 *
 * @phpstan-type ProfileSnapshot array{
 *   entitlement: array<string, mixed>,
 *   groups: list<array{id: int, title: string}>,
 *   renewal: array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null,
 *   lapsed: array{provider: string, ended_at: string}|null,
 *   active_course: array{slug: string, title: string}|null
 * }
 */
final class MeProfileCache
{
    public const PREFIX = 'twa:me:v1:';

    /** Ten minutes: the return to the Mini App after a session still hits; an admin's edit of a course is seen within them. */
    public const TTL = 600;

    public static function key(Learner $learner, string $locale, int $version): string
    {
        return self::PREFIX.$learner->id.':'.$locale.':c'.((int) ($learner->active_course_id ?? 0)).':n'.$version;
    }

    /**
     * The snapshot of the learner, computed by `$load` when there is none (or its plan has run out) and kept for {@see TTL}.
     *
     * @param  Closure(): ProfileSnapshot  $load
     * @return ProfileSnapshot
     */
    public function remember(Learner $learner, string $locale, CarbonImmutable $now, Closure $load): array
    {
        // The version first: a bump that lands while the snapshot is computed moves every later reader to a newer key, and
        // the put below goes to the dead one.
        $key = self::key($learner, $locale, EntitlementResolver::version((int) $learner->id));

        $cached = PureCache::store()->get($key);
        if ($this->isUsable($cached, $now)) {
            /** @var ProfileSnapshot $cached */
            return $cached;
        }

        $snapshot = $load();
        PureCache::store()->put($key, $snapshot, self::TTL);

        return $snapshot;
    }

    private function isUsable(mixed $cached, CarbonImmutable $now): bool
    {
        if (! is_array($cached) || ! is_array($cached['entitlement'] ?? null) || ! array_key_exists('groups', $cached)
            || ! array_key_exists('renewal', $cached) || ! array_key_exists('lapsed', $cached) || ! array_key_exists('active_course', $cached)) {
            return false;
        }

        /** @var array<string, mixed> $entitlement */
        $entitlement = $cached['entitlement'];

        return Entitlement::fromArray($entitlement)->isCurrentAt($now);
    }
}
