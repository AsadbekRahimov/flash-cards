<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\DoneReason;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * Result of CardPicker::pickNext: either a card to show or the reason there is none.
 */
final readonly class CardPick
{
    /**
     * @param  'due'|'new'|'learning'|null  $kind  null exactly when there is no card
     * @param  bool  $ahead  true only for a learn-ahead serve carrying a live `served_ahead_token`
     * @param  DoneReason|null  $doneReason  set exactly when there is no card
     */
    private function __construct(
        public ?WordRepetition $repetition,
        public ?Word $word,
        public ?string $kind,
        public bool $ahead,
        public ?DoneReason $doneReason,
    ) {}

    /**
     * @param  'due'|'new'|'learning'  $kind
     */
    public static function card(WordRepetition $repetition, Word $word, string $kind, bool $ahead = false): self
    {
        return new self($repetition, $word, $kind, $ahead, null);
    }

    public static function done(DoneReason $reason): self
    {
        return new self(null, null, null, false, $reason);
    }

    public function hasCard(): bool
    {
        return $this->word !== null;
    }
}
