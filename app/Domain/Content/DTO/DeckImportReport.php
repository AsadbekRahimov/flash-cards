<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of adding words to a deck: `added` new words, `reactivated` words that were removed
 * earlier, `duplicates` words already in the deck (left untouched), `rejected` lines the parser dropped.
 */
final readonly class DeckImportReport
{
    /** @param  list<array{line:int, reason:string}>  $rejected */
    public function __construct(
        public int $added,
        public int $reactivated,
        public int $duplicates,
        public array $rejected,
    ) {}
}
