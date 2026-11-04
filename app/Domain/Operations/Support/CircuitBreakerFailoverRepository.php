<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Cache\Repository;

/**
 * The repository of a `failover_breaker` chain (docs/19 §4.1): `deleteMultiple()` is ONE call to the chain - one DEL with
 * every key on its Redis member - where the framework's repository forgets key by key, a round trip (and, on a hung
 * instance, a timeout) per key. Everything else is the framework's repository.
 */
final class CircuitBreakerFailoverRepository extends Repository
{
    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        $store = $this->getStore();

        if (! $store instanceof CircuitBreakerFailoverStore) {
            return parent::deleteMultiple($keys);
        }

        $list = [];
        foreach ($keys as $key) {
            $list[] = (string) $key;
        }

        return $store->forgetMany($list);
    }
}
