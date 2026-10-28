<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * SQL statements run by a closure on the default connection (`DB::enableQueryLog()`), for the
 * query-budget tests of the hot paths. Transaction control (BEGIN / SAVEPOINT) is not a query for
 * the log, so only real statements are counted.
 */
final class QueryLog
{
    /**
     * @return list<string> the SQL text (with `?` placeholders) of every statement, in order
     */
    public static function during(Closure $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $fn();
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return array_values(array_map(static fn (array $entry): string => (string) $entry['query'], $log));
    }

    /**
     * The statements of $queries matching $pattern (a PCRE; double quotes and backticks around
     * identifiers are interchangeable in patterns written as `["`]`).
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    public static function matching(array $queries, string $pattern): array
    {
        return array_values(array_filter($queries, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }
}
