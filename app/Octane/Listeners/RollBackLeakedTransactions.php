<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use App\Octane\LeakedTransactionException;
use App\Octane\Support\TransactionBaseline;
use Illuminate\Database\DatabaseManager;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestTerminated;
use Throwable;

/**
 * Safety net for a transaction a request left open (docs/21 §2.2 №32, "правка").
 *
 * Under php-fpm PHP frees the PDO handle at the end of every request and PostgreSQL rolls an open transaction back with
 * the session. An Octane worker keeps its PDO handle between requests, so a transaction that nobody committed or rolled
 * back (an exception between `beginTransaction()` and the commit, a `DB::transaction()` in vendor code that escaped)
 * would continue in the NEXT request of the same worker: its writes would commit together with that request's, or hold
 * a pgbouncer server connection until idle_in_transaction_session_timeout kills it. The application itself only uses
 * `DB::transaction()`, so this is a guard against vendor and future code, and it is reported so the leak gets fixed.
 *
 * Every open connection is checked against the level it had when the request began (TransactionBaseline; 0 when no
 * request recorded one, e.g. after a task); a level above it is rolled back to the baseline. When the rollback itself
 * fails the connection is dropped - the server ends the transaction with the session and the next request reconnects.
 * `transactionLevel()` is a counter: a request without an open transaction costs no query here.
 */
final class RollBackLeakedTransactions
{
    public function handle(OperationTerminated $event): void
    {
        $sandbox = $event->sandbox();
        $baseline = $sandbox->bound(TransactionBaseline::class)
            ? $sandbox->make(TransactionBaseline::class)
            : new TransactionBaseline;

        foreach ($sandbox->make(DatabaseManager::class)->getConnections() as $name => $connection) {
            $level = $connection->transactionLevel();
            $expected = $baseline->levelOf($name);
            if ($level <= $expected) {
                continue;
            }

            $failure = null;
            try {
                $connection->rollBack($expected);
            } catch (Throwable $e) {
                $failure = $e;
                $connection->disconnect();
            }

            report(LeakedTransactionException::forConnection($name, $level, $expected, self::describe($event), $failure));
        }
    }

    private static function describe(OperationTerminated $event): string
    {
        return $event instanceof RequestTerminated
            ? $event->request->method().' /'.ltrim($event->request->path(), '/')
            : 'the operation';
    }
}
