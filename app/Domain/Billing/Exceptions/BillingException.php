<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Base class of the billing domain's business errors. `errorCode` is the stable
 * machine-readable code the HTTP / bot layer maps to a status and a message;
 * `httpStatus()` is the recommended status for the TWA API (docs/12 §3.8).
 *
 * The message is English, for logs and API clients. `context` holds the values it was built
 * from (ids, plan, provider...), so that a panel can write the same error in its own language
 * from `errorCode` + `context` instead of showing the English text (`/admin`: AdminErrors).
 */
abstract class BillingException extends RuntimeException
{
    /** @param  array<string, int|float|string|list<string>>  $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;
}
