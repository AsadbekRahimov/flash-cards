<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Operations\Support\PureCache;
use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * The pure cache of the aggregates the dashboard widgets of `/admin` show (docs/19 §5 K1, docs/20 §1.11): key
 * `admin:widget:<widget>:v1:<scope>[:<params>]` (the format version comes before the parameters, docs/19 §3 rule 3), an array of
 * scalars as the value (never a model or a DTO), on the evicting store ({@see PureCache}): a lost entry is recomputed from the
 * database.
 *
 * `Cache::flexible` semantics (docs/19 §3 rule 6): an entry is FRESH for `$fresh` seconds, then it is still served for
 * four times as long while the next reader recomputes it after the response (a lock keeps it to one recompute), so
 * opening the dashboard never waits for the heavy query except once, when nothing is cached at all.
 *
 * `$scope` is WHO the figures are about (docs/19 §3 rule 8, ids and hashes only): `PanelDataScope::fingerprint()` for
 * widgets a teacher also sees, so a teacher never reads the numbers of an admin or of another teacher's groups. `$params`
 * is WHICH figures of the widget: a day, a page and its size.
 *
 * MONEY (revenue, webhook errors) does not wait for the timer: `MoneyWidgetsObserver` moves a data version on after the commit
 * of every payment, refund and provider transaction ({@see forgetMoney()}), and the version is part of the scope of those
 * entries ({@see moneyScope()}). A version, not a delete, because a delete races: a reader that computed the figure from the
 * ledger before the payment and writes it after the delete would keep the old revenue for the whole freshness window; with a
 * version it writes to the key of the old version, which nobody reads any more (the same way as the entitlement and the
 * overview of a center). The counter has no TTL and lives on the default, non-evicting store (docs/19 §4): were it evicted, the
 * version would fall back to a number an old entry may still carry.
 */
final class WidgetCache
{
    /** Shape of the cached arrays: raise it when a widget changes what it stores, the old keys then just expire. */
    public const VERSION = 'v1';

    /** A widget whose cached shape changed has its own version (the old keys just expire). */
    public const WIDGET_VERSIONS = ['game_activity' => 'v2'];

    /** The data version of the money widgets, on the non-evicting default store. */
    public const MONEY_VERSION_KEY = 'admin:widget:money:ver';

    /** The stale window as a multiple of the fresh one: an entry lives this many times `$fresh` in all. */
    private const LIFETIME_FACTOR = 5;

    /** A recompute that dies must not block the next ones for good (seconds). */
    private const REFRESH_LOCK_SECONDS = 30;

    /**
     * @param  Closure(): array<array-key, mixed>  $compute  the figures, scalars and arrays only
     * @return array<array-key, mixed>
     */
    public function remember(string $widget, string $scope, int $fresh, Closure $compute, string $params = ''): array
    {
        $fresh = max(1, $fresh);
        $store = PureCache::store();

        /** @var array<array-key, mixed> */
        return $store->flexible(
            self::key($widget, $scope, $params),
            [$fresh, $fresh * self::LIFETIME_FACTOR],
            $compute,
            ['seconds' => self::REFRESH_LOCK_SECONDS],
        );
    }

    /** Drops one entry (both the value and the "created at" mark `flexible` keeps beside it). */
    public function forget(string $widget, string $scope, string $params = ''): void
    {
        $key = self::key($widget, $scope, $params);

        PureCache::store()->deleteMultiple([$key, CacheRepository::FLEXIBLE_CREATED_KEY_PREFIX.$key]);
    }

    /**
     * The scope of the money widgets: the admin's and the data version they are read at. The version is read when the key is
     * made, BEFORE the figures are computed; a payment that commits meanwhile moves it on and the write lands on a dead key.
     */
    public function moneyScope(): string
    {
        return 'all:n'.(int) Cache::get(self::MONEY_VERSION_KEY, 0);
    }

    /** The money widgets (admin scope): called after the commit of a payment or a refund. Every entry of the old version is dead at once. */
    public function forgetMoney(): void
    {
        Cache::increment(self::MONEY_VERSION_KEY);
    }

    public static function key(string $widget, string $scope, string $params = ''): string
    {
        return "admin:widget:{$widget}:".(self::WIDGET_VERSIONS[$widget] ?? self::VERSION).":{$scope}".($params === '' ? '' : ":{$params}");
    }
}
