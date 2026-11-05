<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use App\Octane\Support\TransactionBaseline;
use Illuminate\Database\DatabaseManager;
use Laravel\Octane\Events\RequestReceived;

/**
 * Notes the transaction level of every open connection before the request runs (see TransactionBaseline). Reads a
 * counter per connection - no query. Only connections that already exist are listed: one the request opens starts at 0.
 */
final class RememberTransactionLevels
{
    public function handle(RequestReceived $event): void
    {
        $levels = [];
        foreach ($event->sandbox->make(DatabaseManager::class)->getConnections() as $name => $connection) {
            $levels[$name] = $connection->transactionLevel();
        }

        $event->sandbox->instance(TransactionBaseline::class, new TransactionBaseline($levels));
    }
}
