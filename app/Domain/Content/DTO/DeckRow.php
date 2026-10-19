<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * One cleaned, validated line of a deck import. `translation` is the primary text
 * (`words.translation` and the `ru` row of `word_translations`); `translationUz` is optional.
 */
final readonly class DeckRow
{
    public function __construct(
        public string $word,
        public string $translation,
        public ?string $translationUz = null,
        public ?string $example = null,
    ) {}

    /** Case-insensitive identity of the word inside a deck. */
    public function key(): string
    {
        return mb_strtolower($this->word);
    }
}
