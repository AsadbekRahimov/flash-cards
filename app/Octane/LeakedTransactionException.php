<?php

declare(strict_types=1);

namespace App\Octane;

use LogicException;
use Throwable;

/**
 * A request ended with a database transaction still open on a long-lived worker (docs/21 §2.2 №32). Reported, never
 * thrown at the caller: RollBackLeakedTransactions has already rolled the transaction back (or dropped the connection).
 */
final class LeakedTransactionException extends LogicException
{
    public static function forConnection(string $connection, int $level, int $baseline, string $operation, ?Throwable $rollbackFailure = null): self
    {
        $message = sprintf(
            'Database transaction left open on connection "%s" after %s (level %d, expected %d): %s.',
            $connection,
            $operation,
            $level,
            $baseline,
            $rollbackFailure === null
                ? 'rolled back by the Octane worker'
                : 'the rollback failed, the connection was dropped so the server ends the transaction',
        );

        return new self($message, 0, $rollbackFailure);
    }
}
