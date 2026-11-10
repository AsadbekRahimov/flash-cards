<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Operations\Support\PureCache;
use App\Models\TelegramGroup;

/**
 * The number on the «Telegram-группы» item of the `/admin` menu: groups waiting for activation (`status = pending`).
 * The menu is drawn on every page of the panel, so the count is cached for 60 seconds (docs/20 §1, docs/19 §3): key
 * `admin:nav:pending_groups:v1`, an integer as the value, on the evicting store ({@see PureCache}); a lost entry is recounted
 * (`telegram_groups.status` is indexed). `TelegramGroupObserver` drops it after the commit of a new group, a status change and
 * a delete; a count that a reader computed just before and wrote just after the drop lives at most the TTL.
 */
final class PendingGroupsBadge
{
    public const KEY = 'admin:nav:pending_groups:v1';

    public const TTL_SECONDS = 60;

    public static function count(): int
    {
        return PureCache::store()->remember(
            self::KEY,
            self::TTL_SECONDS,
            fn (): int => TelegramGroup::query()->withoutGlobalScopes()->where('status', 'pending')->count(),
        );
    }

    public static function forget(): void
    {
        PureCache::forgetMany([self::KEY]);
    }
}
