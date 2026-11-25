<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A model of a table whose primary key is several columns (`learner_lexemes`, `learner_score_periods`, `learner_score_totals`).
 * Eloquent knows one key column: `$primaryKey` names the first one (what `getKey()` returns), and the UPDATE / DELETE / refresh of
 * a loaded row are scoped by EVERY column of {@see compositeKey()}, so a save never touches the other rows of the same learner.
 * The hot writers use the query builder anyway; this keeps a factory, a test or a panel that saves a model correct.
 */
trait HasCompositeKey
{
    /** @return list<string> the columns of the primary key */
    abstract public function compositeKey(): array;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->compositeKey() as $column) {
            $query->where($column, '=', $this->getOriginal($column) ?? $this->getAttribute($column));
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }
}
