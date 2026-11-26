<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of `lexemes:backfill` (App\Domain\Content\Services\LexemeCatalog::backfill): the words of platform and organisation courses
 * linked, the lexemes created for them, the words of learners' decks unlinked (a deck is outside the catalog, docs/32 §2.1, §12.12) and
 * the words whose spelling has no key (blank).
 */
final class LexemeBackfillReport
{
    public int $catalogWordsLinked = 0;

    public int $deckWordsUnlinked = 0;

    public int $lexemesCreated = 0;

    public int $blank = 0;
}
