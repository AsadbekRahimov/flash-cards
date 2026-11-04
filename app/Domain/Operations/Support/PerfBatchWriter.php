<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Support\Facades\DB;

/**
 * Collects rows of one table and writes them with multi-row INSERTs (`perf:seed`). The batch is cut by the number of
 * bound parameters, not by rows: a statement may carry at most 65535 of them on PostgreSQL and 32766 on SQLite, and
 * the rows of a wide table are many times larger than those of a narrow one.
 */
final class PerfBatchWriter
{
    private const MAX_ROWS = 5000;

    private const MAX_PARAMETERS = 30_000;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private int $batchSize = 0;

    private int $written = 0;

    public function __construct(private readonly string $table) {}

    public function table(): string
    {
        return $this->table;
    }

    /** @param  array<string, mixed>  $row  every row of a writer has the same keys */
    public function add(array $row): void
    {
        if ($this->batchSize === 0) {
            $this->batchSize = max(1, min(self::MAX_ROWS, intdiv(self::MAX_PARAMETERS, max(1, count($row)))));
        }

        $this->rows[] = $row;

        if (count($this->rows) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }

        DB::table($this->table)->insert($this->rows);

        $this->written += count($this->rows);
        $this->rows = [];
    }

    /** @return int the number of rows written by this writer */
    public function close(): int
    {
        $this->flush();

        return $this->written;
    }
}
