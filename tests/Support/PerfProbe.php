<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Measures one call for the baseline of docs/20 §4 and docs/19 §8 (the `perf` Pest group): the number of SQL statements,
 * their summed time, the wall time of the call, and the statement that repeats most - an N+1 shows up there first.
 * The "render" time of docs/20 §1.1 is the wall time minus the SQL time.
 */
final class PerfProbe
{
    /**
     * @param  Closure(): mixed  $call
     * @return array{queries: int, sql_ms: float, ms: float, render_ms: float, repeat: ?string, result: mixed}
     */
    public static function measure(Closure $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $started = hrtime(true);
            $result = $call();
            $ms = (hrtime(true) - $started) / 1_000_000;
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $sql = 0.0;
        $texts = [];
        foreach ($log as $entry) {
            $sql += (float) $entry['time'];
            $texts[] = (string) $entry['query'];
        }

        $repeat = null;
        if ($texts !== []) {
            $counts = array_count_values($texts);
            arsort($counts);
            $sqlText = (string) array_key_first($counts);

            if ($counts[$sqlText] >= 5) {
                $repeat = $counts[$sqlText].'x '.mb_substr((string) preg_replace('/\s+/', ' ', $sqlText), 0, 110);
            }
        }

        return ['queries' => count($log), 'sql_ms' => $sql, 'ms' => $ms, 'render_ms' => max(0.0, $ms - $sql), 'repeat' => $repeat, 'result' => $result];
    }

    /** Nearest-rank percentile of $values ($p from 0 to 100). */
    public static function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $rank = (int) max(1, ceil($p / 100 * count($values)));

        return (float) $values[min(count($values), $rank) - 1];
    }

    /**
     * @param  list<array{queries: int, sql_ms: float, ms: float, render_ms: float, repeat: ?string}>  $runs
     * @return array{queries: int, queries_max: int, sql_ms: float, render_ms: float, p50: float, p95: float, repeat: ?string}
     */
    public static function summarize(array $runs): array
    {
        $totals = array_column($runs, 'ms');
        $queries = array_column($runs, 'queries');

        return [
            'queries' => (int) self::percentile($queries, 50),
            'queries_max' => $queries === [] ? 0 : max($queries),
            'sql_ms' => self::percentile(array_column($runs, 'sql_ms'), 50),
            'render_ms' => self::percentile(array_column($runs, 'render_ms'), 50),
            'p50' => self::percentile($totals, 50),
            'p95' => self::percentile($totals, 95),
            'repeat' => collect($runs)->pluck('repeat')->filter()->first(),
        ];
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function markdown(array $header, array $rows): string
    {
        $lines = ['| '.implode(' | ', $header).' |', '|'.str_repeat('---|', count($header))];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn ($cell): string => str_replace('|', '\\|', (string) $cell), $row)).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
