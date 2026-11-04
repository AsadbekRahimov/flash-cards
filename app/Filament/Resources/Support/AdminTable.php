<?php

declare(strict_types=1);

namespace App\Filament\Resources\Support;

use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The settings the list tables of /admin share (docs/20 §1.6, §1.7).
 *
 * A table that can grow offers the page sizes of {@see PAGE_SIZES} - never "all" - and ENFORCES them: `tableRecordsPerPage`
 * is a public Livewire property, so without the enforcement a request can ask for "all" or for a million rows whatever the
 * select shows. A big table (the learners, the words, the payments: hundreds of thousands of rows) also pages without
 * counting its rows (no `COUNT(*)`: "previous / next" instead of page numbers), searches the typed text as one phrase
 * (one predicate per column instead of one per word and column) and waits for the typing to stop before it asks.
 */
final class AdminTable
{
    /** Rows per page the admin chooses from; the first one is the default. */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * A table that can grow: the page size comes from {@see PAGE_SIZES} only.
     */
    public static function bounded(Table $table): Table
    {
        return $table
            ->paginationPageOptions(self::PAGE_SIZES)
            ->enforcePaginationPageOptions();
    }

    /**
     * A big table. `$splitsSearch` keeps the search by words where it is what people type (a full name "Aziz Karimov" is two
     * words that find one person).
     */
    public static function big(Table $table, bool $splitsSearch = false): Table
    {
        return self::bounded($table)
            ->paginationMode(PaginationMode::Simple)
            ->splitSearchTerms($splitsSearch)
            ->searchDebounce('750ms');
    }

    /**
     * `$column` contains `$term`, case-insensitively, with `%`, `_` and `!` of the term taken literally (an own LIKE search
     * without this lets "a_b" find "axb" and "%" find everything). The pattern is escaped with `!` and says so with an
     * explicit `ESCAPE '!'` - the escape character of Filament's own search, so it means the same on PostgreSQL and SQLite and
     * does not depend on `standard_conforming_strings`.
     *
     * The form of the comparison is the form of the trigram index under the column (docs/03 §2.17, docs/20 F2), or the search
     * scans the table. By default PostgreSQL compares `ilike` on the column itself - the form of the plain index of
     * `learners.username`. With `$lower` it compares `lower(column) like` the lower-cased term, as Filament's own search emits
     * and as the indexes on `lower(column)` expect (the names of learners, the words). SQLite's `like` is case-insensitive for
     * ASCII either way.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column  a column name written in the code (`learners.username`), never taken from input
     * @param  'and'|'or'  $boolean
     * @param  bool  $lower  the column is indexed as `lower(column)`
     * @return Builder<TModel>
     */
    public static function whereContains(Builder $query, string $column, string $term, string $boolean = 'and', bool $lower = false): Builder
    {
        // The column name is part of the code, the term is a binding.
        if ($lower) {
            return $query->whereRaw("lower({$column}) like ? escape '!'", ['%'.self::escapeLike(mb_strtolower($term)).'%'], $boolean);
        }

        $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->whereRaw("{$column} {$operator} ? escape '!'", ['%'.self::escapeLike($term).'%'], $boolean);
    }

    /**
     * The learners whose first name, last name or username contains `$term`: the search of every picker of learners (members of
     * /org, the lists and pickers of members and subscriptions in /admin). The forms are the ones of the trigram indexes of
     * `learners` (docs/03 §2.17): `lower(name) like` for the names, `ilike` on the plain `username`, so the OR of the three is
     * a BitmapOr of the indexes on PostgreSQL. "@name" and "name" both find the username (the leading @ is not stored).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $learners  a query on `learners`, or the subquery of a relation to them (`whereHas('learner', …)`)
     * @return Builder<TModel>
     */
    public static function whereLearnerContains(Builder $learners, string $term): Builder
    {
        $term = trim($term);

        return $learners->where(function (Builder $match) use ($term): void {
            self::whereContains($match, 'learners.first_name', $term, lower: true);
            self::whereContains($match, 'learners.last_name', $term, 'or', lower: true);
            self::whereContains($match, 'learners.username', ltrim($term, '@'), 'or');
        });
    }

    /**
     * A search term as the literal part of a LIKE pattern: `%`, `_` and `!` escaped with `!`. The pattern must be compared with
     * `ESCAPE '!'` (see {@see whereContains()}). Not a backslash: with `ESCAPE '\'` in the SQL text Laravel's `toRawSql()` takes
     * `\'` for an escaped quote and puts the bindings in the wrong places (a debugging aid, but one worth keeping right).
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /**
     * The search of an identifier (a payment id, a charge id): it matches the whole identifier, never a part of it - so it
     * can use the index of the column instead of scanning the table with a leading wildcard. A text that cannot be an
     * identifier (empty, or with a space inside) matches nothing.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereIdentifier(Builder $query, string $column, string $search): Builder
    {
        $identifier = trim($search);

        return $identifier === '' || preg_match('/\s/', $identifier) === 1
            ? $query->whereNull($query->getModel()->getQualifiedKeyName())
            : $query->where($column, $identifier);
    }
}
