<?php

declare(strict_types=1);

namespace App\Octane;

use LogicException;
use Throwable;

/**
 * A database transaction was left open on a long-lived worker (docs/21 §2.2 №32): found after a request by
 * RollBackLeakedTransactions, or - when that hook did not run - on the way into the next request by
 * RememberTransactionLevels. Reported, never thrown at the caller: the listener has already rolled the transaction back
 * (or dropped the connection).
 */
final class LeakedTransactionException extends LogicException
{
    /** The request (or task) that just ended left a transaction open. */
    public static function forConnection(string $connection, int $level, int $baseline, string $operation, ?Throwable $rollbackFailure = null): self
    {
        $message = sprintf(
            'Database transaction left open on connection "%s" after %s (level %d, expected %d): %s.',
            $connection,
            $operation,
            $level,
            $baseline,
            self::outcome($rollbackFailure),
        );

        return new self($message, 0, $rollbackFailure);
    }

    /**
     * A transaction was already open when a request reached the worker: the previous request or task of this worker
     * ended without its termination hooks (an exception in `terminate()` or while sending the response), so the
     * end-of-request guard never saw it.
     */
    public static function foundOnEntry(string $connection, int $level, string $operation, ?Throwable $rollbackFailure = null): self
    {
        $message = sprintf(
            'Database transaction found open on connection "%s" on entry to %s (level %d, expected 0) - the previous'
            .' request or task of this worker ended without its termination hooks: %s.',
            $connection,
            $operation,
            $level,
            self::outcome($rollbackFailure),
        );

        return new self($message, 0, $rollbackFailure);
    }

    private static function outcome(?Throwable $rollbackFailure): string
    {
        return $rollbackFailure === null
            ? 'rolled back by the Octane worker'
            : 'the rollback failed, the connection was dropped so the server ends the transaction';
    }
}
