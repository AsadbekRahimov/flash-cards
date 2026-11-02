<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Operations\Support\PureCache;
use Closure;
use Illuminate\Cache\Repository as CacheRepository;

/**
 * The pure cache of the aggregates the dashboard widgets of `/admin` show (docs/19 §5 K1, docs/20 §1.11): key
 * `admin:widget:<widget>:v1:<scope>`, an array of scalars as the value (never a model or a DTO), on the evicting store
 * ({@see PureCache}): a lost entry is recomputed from the database.
 *
 * `Cache::flexible` semantics (docs/19 §3 rule 6): an entry is FRESH for `$fresh` seconds, then it is still served for
 * four times as long while the next reader recomputes it after the response (a lock keeps it to one recompute), so
 * opening the dashboard never waits for the heavy query except once, when nothing is cached at all. Callers whose
 * figures move at once on an event drop the entry ({@see forget()}, {@see forgetMoney()}).
 *
 * `$scope` is WHAT the figures are about (docs/19 §3 rule 8, ids and hashes only): `PanelDataScope::fingerprint()` for
 * widgets a teacher also sees, so a teacher never reads the numbers of an admin or of another teacher's groups.
 */
final class WidgetCache
{
    /** Shape of the cached arrays: raise it when a widget changes what it stores, the old keys then just expire. */
    public const VERSION = 'v1';

    /** Widgets whose figures are money: dropped after a payment or a refund (`PaymentObserver`), not left to the TTL. */
    public const MONEY_WIDGETS = ['revenue_30d', 'webhook_errors'];

    /** The stale window as a multiple of the fresh one: an entry lives this many times `$fresh` in all. */
    private const LIFETIME_FACTOR = 5;

    /** A recompute that dies must not block the next ones for good (seconds). */
    private const REFRESH_LOCK_SECONDS = 30;

    /**
     * @param  Closure(): array<array-key, mixed>  $compute  the figures, scalars and arrays only
     * @return array<array-key, mixed>
     */
    public function remember(string $widget, string $scope, int $fresh, Closure $compute): array
    {
        $fresh = max(1, $fresh);
        $store = PureCache::store();

        /** @var array<array-key, mixed> */
        return $store->flexible(
            self::key($widget, $scope),
            [$fresh, $fresh * self::LIFETIME_FACTOR],
            $compute,
            ['seconds' => self::REFRESH_LOCK_SECONDS],
        );
    }

    /** Drops one entry (both the value and the "created at" mark `flexible` keeps beside it). */
    public function forget(string $widget, string $scope): void
    {
        $key = self::key($widget, $scope);

        PureCache::store()->deleteMultiple([$key, CacheRepository::FLEXIBLE_CREATED_KEY_PREFIX.$key]);
    }

    /** The money widgets (admin scope): called after the commit of a payment or a refund. */
    public function forgetMoney(): void
    {
        foreach (self::MONEY_WIDGETS as $widget) {
            $this->forget($widget, 'all');
        }
    }

    public static function key(string $widget, string $scope): string
    {
        return "admin:widget:{$widget}:".self::VERSION.":{$scope}";
    }
}
