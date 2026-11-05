<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use App\Octane\LeakedTransactionException;
use App\Octane\Support\TransactionBaseline;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Throwable;

/**
 * Notes the transaction level of every open connection before the request runs (see TransactionBaseline). Reads a
 * counter per connection - no query. Only connections that already exist are listed: one the request opens starts at 0.
 *
 * Outside the test-suite the baseline is always 0, and a connection found ABOVE 0 here is a leak the end-of-request
 * guard (RollBackLeakedTransactions, OperationTerminated) never saw: when `Worker::handle()` fails after the response
 * (an exception in `terminate()`, in a terminating middleware or while the client sends the response) no
 * OperationTerminated is dispatched - the sandbox is just flushed - and the PDO handle keeps the transaction. Taking that
 * level as the baseline would hide it until the worker restarts: it would never be rolled back, every
 * `DB::transaction()` of every later request would be a savepoint inside it, and nothing would ever commit. So the
 * transaction is rolled back to 0 (the connection is dropped when the rollback itself fails) and reported.
 *
 * Under the test-suite (`runningUnitTests()`, i.e. `APP_ENV=testing`) RefreshDatabase holds a transaction of its own
 * around every test: that level IS the baseline, and the listener must not touch it (WorkerIsolationTest). `$event->app`
 * is the base application the sandbox was cloned from; both carry the same `env`.
 */
final class RememberTransactionLevels
{
    public function handle(RequestReceived $event): void
    {
        $keepOpenTransactions = $event->app->runningUnitTests();

        $levels = [];
        foreach ($event->sandbox->make(DatabaseManager::class)->getConnections() as $name => $connection) {
            $level = $connection->transactionLevel();

            if ($level > 0 && ! $keepOpenTransactions) {
                $this->rollBackLeak($name, $connection, $level, $event->request);
                $level = 0;
            }

            $levels[$name] = $level;
        }

        $event->sandbox->instance(TransactionBaseline::class, new TransactionBaseline($levels));
    }

    private function rollBackLeak(string $name, Connection $connection, int $level, Request $request): void
    {
        $failure = null;
        try {
            $connection->rollBack(0);
        } catch (Throwable $e) {
            $failure = $e;
            $connection->disconnect();
        }

        report(LeakedTransactionException::foundOnEntry($name, $level, self::describe($request), $failure));
    }

    private static function describe(Request $request): string
    {
        return $request->method().' /'.ltrim($request->path(), '/');
    }
}
