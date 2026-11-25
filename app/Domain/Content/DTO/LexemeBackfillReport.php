<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of `lexemes:backfill` (App\Domain\Content\Services\LexemeCatalog::backfill): the words linked in each pass, the
 * lexemes the catalog pass created, the deck words that match nothing in the catalog (left unlinked: no public points,
 * docs/28) and the words whose spelling has no key (blank).
 */
final class LexemeBackfillReport
{
    public int $catalogWordsLinked = 0;

    public int $deckWordsLinked = 0;

    public int $lexemesCreated = 0;

    public int $unmatched = 0;

    public int $blank = 0;
}
