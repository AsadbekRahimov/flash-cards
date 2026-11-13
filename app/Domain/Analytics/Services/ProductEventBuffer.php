<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Models\ProductEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The product events of ONE HTTP request of the API, gathered to be written with a single INSERT after the response
 * has been sent (App\Http\Middleware\DeferProductEvents). The state is the request's: the binding is `scoped`
 * (AppServiceProvider), so the queue worker forgets it before each job and Octane between requests (docs/21 §2.2 №11).
 *
 * Why the recorder and the middleware take it from the CURRENT container (`app()`) and never keep it in a field: the
 * recorder is injected into services that Octane may resolve at worker boot through the base application, while the
 * middleware runs in the sandbox of the request - a constructor-injected scoped buffer would be two different
 * instances, and the events would be gathered in one and flushed from the other (docs/21 §2.2 №12).
 *
 * Analytics is best effort: the rows live only in this process between the response and `finish()`, so a crash of the
 * process in that window loses them. That is the accepted price of not delaying the learner's response.
 *
 * Never throws: a failed write is logged (`product_event.record_failed`) and swallowed, like ProductEventRecorder.
 */
class ProductEventBuffer
{
    private bool $deferring = false;

    /** @var list<array<string, mixed>> rows as the model would insert them (props already JSON, dates formatted) */
    private array $rows = [];

    /** Starts gathering: the recorder hands its rows here instead of inserting them. */
    public function defer(): void
    {
        $this->deferring = true;
    }

    public function isDeferring(): bool
    {
        return $this->deferring;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function add(array $row): void
    {
        $this->rows[] = $row;

        // A row that arrives after `finish()` (an after-commit callback of a late job) has nobody to flush it later.
        if (! $this->deferring) {
            $this->flush();
        }
    }

    /**
     * Writes everything gathered so far with ONE multi-row INSERT. The buffer is emptied BEFORE the attempt, so a
     * second flush can never write a row twice. If the batch fails, the rows go one by one, each in its own
     * try / catch: one bad row (a learner deleted in the meantime - FK) must not cost the others.
     */
    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }

        $rows = $this->rows;
        $this->rows = [];

        try {
            $this->insert($rows);

            return;
        } catch (\Throwable) {
            // fall through to the row-by-row write
        }

        foreach ($rows as $row) {
            try {
                $this->insert([$row]);
            } catch (\Throwable $e) {
                Log::warning('product_event.record_failed', [
                    'event' => (string) ($row['name'] ?? ''),
                    'exception' => $e::class,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);
            }
        }
    }

    /** Ends the gathering (the request is over) and writes the rows; whatever is recorded later is written at once. */
    public function finish(): void
    {
        $this->deferring = false;
        $this->flush();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(array $rows): void
    {
        // Not expected after the response, but if a transaction is open, a failed INSERT would poison it on Postgres:
        // the attempt then runs under a savepoint (as ProductEventRecorder does), so a failure rolls back to it only.
        if (DB::transactionLevel() > 0) {
            DB::transaction(static function () use ($rows): void {
                ProductEvent::query()->insert($rows);
            });
        } else {
            ProductEvent::query()->insert($rows);
        }
    }
}
