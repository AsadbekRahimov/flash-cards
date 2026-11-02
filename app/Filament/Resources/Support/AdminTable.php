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
