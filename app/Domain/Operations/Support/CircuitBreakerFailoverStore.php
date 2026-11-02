<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Operations\Events\CacheCircuitClosed;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;

/**
 * Laravel's failover chain with a circuit breaker in front of every member but the last (docs/19 §4.1): a member that
 * failed is skipped for a window instead of being tried - and timed out - on every call of every request. The cache driver
 * `failover_breaker` (registered in AppServiceProvider); the pure store `lru` = [`lru_redis`, `array`] is one.
 *
 * What differs from FailoverStore: a member whose breaker is open is not tried at all; `CacheFailedOver` is dispatched when
 * the breaker OPENS - once per opening across all the processes that share the marker, not once per process and outage -
 * and `CacheCircuitClosed` when a probe succeeds; `lock()` and `restoreLock()` do not consult the breaker, because the lock
 * of a Redis member lives on its `lock_connection` (the non-evicting instance) and never touches the guarded one;
 * `forgetMany()` drops many keys with ONE command on a Redis member. The last member is the floor of the chain: there is
 * nothing to fall over to, so it is always tried.
 */
final class CircuitBreakerFailoverStore extends FailoverStore
{
    /**
     * @param  list<string>  $stores
     */
    public function __construct(CacheManager $cache, Dispatcher $events, array $stores, private readonly CacheCircuitBreaker $breaker)
    {
        parent::__construct($cache, $events, $stores);
    }

    public function breaker(): CacheCircuitBreaker
    {
        return $this->breaker;
    }

    /**
     * {@inheritdoc}
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->attempt(fn (CacheRepository $member) => self::lockProviderOf($member)->lock($name, $seconds, $owner), guarded: false);
    }

    /**
     * {@inheritdoc}
     */
    public function restoreLock($name, $owner)
    {
        return $this->attempt(fn (CacheRepository $member) => self::lockProviderOf($member)->restoreLock($name, $owner), guarded: false);
    }

    /**
     * Drops the keys with one command on a Redis member (one DEL with every key), the repository's loop on any other.
     *
     * @param  list<string>  $keys
     */
    public function forgetMany(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        return (bool) $this->attempt(fn (CacheRepository $member): bool => self::forgetManyOn($member, $keys));
    }

    /**
     * ONE command for a Redis store - DEL with every key under the store's prefix - and `deleteMultiple` (a forget per key)
     * for any other repository.
     *
     * @param  list<string>  $keys
     */
    public static function forgetManyOn(Repository $repository, array $keys): bool
    {
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        if ($store instanceof RedisStore) {
            $prefix = $store->getPrefix();
            $store->connection()->command('del', [array_map(static fn (string $key): string => $prefix.$key, $keys)]);

            return true;
        }

        return $repository->deleteMultiple($keys);
    }

    /**
     * {@inheritdoc}
     *
     * @param  list<mixed>  $arguments
     */
    protected function attemptOnAllStores(string $method, array $arguments)
    {
        return $this->attempt(fn (CacheRepository $member): mixed => $member->{$method}(...$arguments));
    }

    /**
     * The first member that answers, in order; a member whose breaker is open is skipped without a call.
     *
     * @param  Closure(CacheRepository): mixed  $call
     *
     * @throws Throwable the last failure, when no member answered
     */
    private function attempt(Closure $call, bool $guarded = true): mixed
    {
        $last = null;
        $floor = count($this->stores) - 1;

        foreach (array_values($this->stores) as $index => $name) {
            $guard = $guarded && $index < $floor;

            if ($guard && ! $this->breaker->allows($name)) {
                continue;
            }

            try {
                $result = $call($this->member($name));
            } catch (Throwable $e) {
                $last = $e;

                if ($guard && $this->breaker->failed($name)) {
                    $this->events->dispatch(new CacheFailedOver($name, $e));
                }

                continue;
            }

            if ($guard && $this->breaker->succeeded($name)) {
                $this->events->dispatch(new CacheCircuitClosed($name));
            }

            return $result;
        }

        throw $last ?? new RuntimeException('All failover cache stores failed or are behind an open circuit breaker.');
    }

    private static function lockProviderOf(CacheRepository $member): LockProvider
    {
        $store = $member->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('This cache store does not support locks.');
        }

        return $store;
    }

    /** The cache manager builds every store as a Repository; the contract alone has no getStore(). */
    private function member(string $name): CacheRepository
    {
        $repository = $this->store($name);

        if (! $repository instanceof CacheRepository) {
            throw new RuntimeException("Cache store [{$name}] is not a cache repository.");
        }

        return $repository;
    }
}
