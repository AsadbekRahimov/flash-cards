<?php

declare(strict_types=1);

namespace App\Domain\Operations\Events;

/**
 * The probe of a half-open circuit breaker (docs/19 §4.1) reached the member again: the chain is back on it. The
 * counterpart of Laravel's CacheFailedOver, which the chain dispatches when the breaker opens.
 */
final readonly class CacheCircuitClosed
{
    public function __construct(public string $storeName) {}
}
