<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** Deck limits of a learner's current plan and how many decks they already use. */
final readonly class DeckLimits
{
    public function __construct(
        public int $decks,
        public int $deckWords,
        public int $usedDecks,
    ) {}
}
