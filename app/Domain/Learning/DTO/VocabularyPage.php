<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

/**
 * One page of "My vocabulary" (docs/40 §2.3, docs/04 A.25): the rows in the alphabetical order of the word, the cursor of the next page
 * (null - the last one) and, on the first page only (no `after`), the counts of the three chips.
 */
final readonly class VocabularyPage
{
    /**
     * @param  list<array{word_id: int, word: string, translation: string, translation_fallback: bool, transcription: string|null, audio_url: string|null, strength: int<0, 5>, stage: string, next_in_days: int|null, is_hard: bool}>  $items
     * @param  array{all: int, weak: int, strong: int}|null  $counts  null on a page after a cursor
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?array $counts,
    ) {}
}
