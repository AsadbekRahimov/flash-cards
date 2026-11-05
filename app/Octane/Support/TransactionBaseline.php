<?php

declare(strict_types=1);

namespace App\Octane\Support;

/**
 * The transaction level of every open database connection at the moment a request reached the worker
 * (RememberTransactionLevels), kept in the sandbox of that request so RollBackLeakedTransactions can tell a transaction
 * the request left open from one that was open before it started. Outside the test-suite that level is 0 for every
 * connection - RememberTransactionLevels rolls back and reports a transaction it finds open on entry; in the test-suite
 * RefreshDatabase wraps each test in a transaction of its own, which the listeners must not touch.
 */
final class TransactionBaseline
{
    /** @param array<string, int> $levels connection name => transaction level */
    public function __construct(private readonly array $levels = []) {}

    /** The level a connection had when the request began; 0 for a connection opened during the request. */
    public function levelOf(string $connection): int
    {
        return $this->levels[$connection] ?? 0;
    }
}
