<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

/**
 * What a meaning / reverse / audio card was sent without (it gives the right option away, docs/06 §12.2) and comes back
 * with the verdict of the answer: the card's screen after the pick.
 */
final readonly class RevealedCard
{
    /**
     * @param  string  $correct  the right option exactly as it stands among the options (reverse: the word; meaning / audio: the translation)
     * @param  array{word: string, translation: string|null}|null  $confusedWith
     */
    public function __construct(
        public string $word,
        public string $translation,
        public string $correct,
        public ?string $example,
        public ?string $transcription,
        public ?string $audioUrl,
        public ?string $hook,
        public ?string $note,
        public ?array $confusedWith,
    ) {}
}
