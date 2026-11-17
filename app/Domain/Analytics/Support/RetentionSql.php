<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use Illuminate\Database\Connection;
use LogicException;

/**
 * The few SQL fragments of the retention report that differ between PostgreSQL (production) and sqlite (tests): casts of
 * bound values, the date of a timestamp, shifting a timestamp by seconds, the whole days between two dates. Everything else
 * of the report is plain SQL shared by both drivers, and every value reaches the query as a binding (never as text).
 *
 * A learner's local calendar is NOT computed in SQL with a time-zone database (`AT TIME ZONE` has no sqlite twin and would
 * make the two drivers two implementations): {@see offsetCase()} turns the offsets PHP knows for the zones that occur
 * (DST transitions included) into a `CASE` over the learner's `timezone` column, so a learner's local date is
 * `date(created_at + offset)` on both.
 */
final class RetentionSql
{
    private function __construct(private readonly bool $pgsql) {}

    public static function for(Connection $connection): self
    {
        $driver = $connection->getDriverName();

        return match ($driver) {
            'pgsql' => new self(true),
            'sqlite' => new self(false),
            default => throw new LogicException("The retention report supports pgsql and sqlite, not '{$driver}'."),
        };
    }

    /** A bound `Y-m-d H:i:s` string compared with a timestamp column (sqlite keeps timestamps as text, so no cast there). */
    public function ts(): string
    {
        return $this->pgsql ? 'CAST(? AS timestamp)' : '?';
    }

    /** A bound integer used as a value of an expression: an untyped parameter in a `CASE` would make the result text. */
    public function int(): string
    {
        return 'CAST(? AS integer)';
    }

    /** The calendar date of a timestamp expression (`Y-m-d`: a `date` on PostgreSQL, text on sqlite). */
    public function dateOf(string $timestamp): string
    {
        return $this->pgsql ? "CAST({$timestamp} AS date)" : "date({$timestamp})";
    }

    /** A timestamp expression moved by an integer expression of seconds. */
    public function shift(string $timestamp, string $seconds): string
    {
        return $this->pgsql
            ? "({$timestamp} + ({$seconds}) * interval '1 second')"
            : "datetime({$timestamp}, (({$seconds}) || ' seconds'))";
    }

    /** `learner_daily_stats.date` as `Y-m-d` (sqlite stores a `date` as `Y-m-d 00:00:00`, see StoredDate). */
    public function storedDate(string $column): string
    {
        return $this->pgsql ? $column : "date({$column})";
    }

    /** Whole days from `$from` to `$to`, two `Y-m-d` date expressions. */
    public function daysBetween(string $to, string $from): string
    {
        return $this->pgsql
            ? "({$to} - {$from})"
            : "CAST(ROUND(julianday({$to}) - julianday({$from})) AS INTEGER)";
    }

    /**
     * Offset from UTC in seconds of the learner's zone, as a `CASE` over `$zoneColumn`. `$zones` maps a zone name as stored in
     * the column to its offset history, oldest first: `[[from unix time, offset seconds], ...]`; with more than one entry
     * the offset in force at `$timestampColumn` (a UTC `Y-m-d H:i:s` column) is picked, with one it is constant (the
     * offset "now"). A name that is not in `$zones` has no offset: UTC.
     *
     * @param  array<string, non-empty-list<array{int, int}>>  $zones
     * @return array{string, list<int|string>} SQL and its bindings, in the order of the placeholders
     */
    public function offsetCase(string $zoneColumn, ?string $timestampColumn, array $zones): array
    {
        if ($zones === []) {
            return [$this->int(), [0]];
        }

        $sql = "CASE {$zoneColumn}";
        $bindings = [];

        foreach ($zones as $name => $history) {
            $sql .= ' WHEN ? THEN ';
            $bindings[] = (string) $name;

            if ($timestampColumn === null || count($history) === 1) {
                $sql .= $this->int();
                $bindings[] = $history[array_key_last($history)][1];

                continue;
            }

            $sql .= '(CASE';
            for ($i = count($history) - 1; $i >= 1; $i--) {
                $sql .= " WHEN {$timestampColumn} >= {$this->ts()} THEN {$this->int()}";
                $bindings[] = gmdate('Y-m-d H:i:s', $history[$i][0]);
                $bindings[] = $history[$i][1];
            }
            $sql .= " ELSE {$this->int()} END)";
            $bindings[] = $history[0][1];
        }

        return [$sql.' ELSE '.$this->int().' END', [...$bindings, 0]];
    }
}
