<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

/**
 * A plan key that is not a configured plan (Plan::fromKey).
 *
 *   plan_unknown  422  the key is not a `Plan` case or has no entry in config('billing.plans')
 *
 * Raised for keys coming from forms (Filament promo / grant), from stored rows
 * whose plan was removed from the config, and from the TWA — a domain error the
 * HTTP / bot layer can map, instead of a bare ValueError / 500.
 */
final class PlanException extends BillingException
{
    public const UNKNOWN = 'plan_unknown';

    public static function unknown(string $key): self
    {
        return new self(self::UNKNOWN, "Unknown billing plan [{$key}].");
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
