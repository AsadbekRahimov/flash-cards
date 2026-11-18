<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Several scalar reads of a screen in ONE statement: `select (select count(*) ...) as a, (select x ... limit 1) as b limit 1`
 * (docs/19 K9, K10). Each member is a subquery of its own that the planner runs as an independent InitPlan on its own index,
 * so the statement costs what the separate statements cost, minus the round trips. For counters and single values that are
 * read together and change with every grade (nothing here is cached): the Today screen's due cards, the quota, the candidates of
 * the round of hard words, the quiz of the week, the "has groups" fact.
 *
 * The aliases are plain identifiers (`[a-z_]+`); a reader takes the row by them and casts ({@see int()}): drivers return
 * counts as int (SQLite) or string (PostgreSQL through PDO without native prepares), dates as strings.
 */
final class ScalarBatch
{
    /** @var array<string, Builder> alias => the subquery that yields the scalar */
    private array $members = [];

    /**
     * `count(*)` of the rows of `$rows` (its own select list is replaced).
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $rows
     */
    public function count(string $alias, EloquentBuilder|Builder $rows): self
    {
        return $this->add($alias, $this->base($rows)->selectRaw('count(*)'));
    }

    /**
     * `count(*)` of the rows of `$rows` AS THEY ARE, its `limit` included: `select count(*) from (select ... limit N) as t` - for
     * "how many candidates up to N", where the subquery's order and limit must stay.
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $rows
     */
    public function countLimited(string $alias, EloquentBuilder|Builder $rows): self
    {
        return $this->add($alias, DB::query()->fromSub($this->base($rows), $alias.'_rows')->selectRaw('count(*)'));
    }

    /**
     * The single value `$scalar` selects (one column; the caller limits it to one row where the predicate may match several).
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $scalar
     */
    public function value(string $alias, EloquentBuilder|Builder $scalar): self
    {
        return $this->add($alias, $this->base($scalar));
    }

    public function isEmpty(): bool
    {
        return $this->members === [];
    }

    /** @return list<string> the aliases, in the order they were added */
    public function aliases(): array
    {
        return array_keys($this->members);
    }

    /**
     * Runs the statement; `[]` when nothing was added (no statement then).
     *
     * @return array<string, mixed> alias => the raw scalar of the driver (null when the subquery yields no row)
     */
    public function run(): array
    {
        if ($this->members === []) {
            return [];
        }

        $statement = DB::query();
        foreach ($this->members as $alias => $subquery) {
            $statement->selectSub($subquery, $alias);
        }

        /** @var object|null $row */
        $row = $statement->first();
        $values = [];
        foreach (array_keys($this->members) as $alias) {
            $values[$alias] = $row === null ? null : ($row->{$alias} ?? null);
        }

        return $values;
    }

    /** @param  array<string, mixed>  $row */
    public static function int(array $row, string $alias): int
    {
        $value = $row[$alias] ?? 0;

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private function add(string $alias, Builder $subquery): self
    {
        if (preg_match('/^[a-z_]+$/', $alias) !== 1) {
            throw new InvalidArgumentException("Scalar alias '{$alias}' is not a plain identifier.");
        }
        if (array_key_exists($alias, $this->members)) {
            throw new InvalidArgumentException("Scalar alias '{$alias}' is already in the batch.");
        }

        $this->members[$alias] = $subquery;

        return $this;
    }

    /**
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>|Builder  $query
     */
    private function base(EloquentBuilder|Builder $query): Builder
    {
        return $query instanceof EloquentBuilder ? $query->toBase() : $query;
    }
}
