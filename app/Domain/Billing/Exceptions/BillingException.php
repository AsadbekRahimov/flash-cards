<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Base class of the billing domain's business errors. `errorCode` is the stable
 * machine-readable code the HTTP / bot layer maps to a status and a message;
 * `httpStatus()` is the recommended status for the TWA API (docs/12 §3.8).
 */
abstract class BillingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;
}
