<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Services\DmReachability;
use App\Models\Learner;

/**
 * «Заблокировали бота: N (за 7 дней: M)» of the dashboard of `/admin` (docs/34 §4в п. 4): the learners whose private chat is closed
 * (`dm_blocked_at IS NOT NULL`, soft-deleted ones aside) and those of them closed in the last 7 days. Two counts over the partial
 * index `learners_dm_blocked_at_idx`; cached for 60 seconds as an array of two integers on the evicting store ({@see PureCache},
 * docs/19 §3, docs/20 §1.11), like {@see PendingLeagueMonthsBadge}: key `admin:widget:bot_blocked:v1:all`. Platform-wide, so for an
 * active admin only. {@see DmReachability} drops it after the commit of a transition (closed ↔ open); a count a reader wrote just
 * after the drop lives at most the TTL.
 */
final class BotBlockedCounter
{
    public const KEY = 'admin:widget:bot_blocked:v1:all';

    public const TTL_SECONDS = 60;

    /** @return array{total: int, week: int} */
    public static function counts(): array
    {
        $cached = PureCache::store()->get(self::KEY);
        if (is_array($cached) && isset($cached['total'], $cached['week'])) {
            return ['total' => (int) $cached['total'], 'week' => (int) $cached['week']];
        }

        $counts = [
            'total' => Learner::query()->whereNotNull('dm_blocked_at')->count(),
            'week' => Learner::query()->where('dm_blocked_at', '>=', now()->subDays(7))->count(),
        ];
        PureCache::store()->put(self::KEY, $counts, self::TTL_SECONDS);

        return $counts;
    }

    public static function forget(): void
    {
        PureCache::forgetMany([self::KEY]);
    }
}
