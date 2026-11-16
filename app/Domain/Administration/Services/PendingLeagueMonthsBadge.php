<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueMonth;

/**
 * The number on the «Рейтинг месяца» item of the `/admin` menu: months waiting for the administrator's review
 * (`status = pending_review`, docs/13 §7.6) — normally 0 or 1. Cached for 60 seconds as an integer on the evicting store
 * ({@see PureCache}, docs/19 §3, docs/20 §1): key `admin:nav:pending_league_months:v1`. `MonthlyRatingService::closeMonth`
 * and `MonthlyRatingReviewService::confirm` drop it after the commit of a status change; a count a reader wrote just
 * after the drop lives at most the TTL.
 */
final class PendingLeagueMonthsBadge
{
    public const KEY = 'admin:nav:pending_league_months:v1';

    public const TTL_SECONDS = 60;

    public static function count(): int
    {
        // Redis hands a cached number back as a string ("1"); without the cast every page of the panel would answer 500
        // for the TTL under strict types (the incident of PendingGroupsBadge).
        return (int) PureCache::store()->remember(
            self::KEY,
            self::TTL_SECONDS,
            fn (): int => LeagueMonth::query()->where('status', LeagueMonthStatus::PendingReview->value)->count(),
        );
    }

    public static function forget(): void
    {
        PureCache::forgetMany([self::KEY]);
    }
}
