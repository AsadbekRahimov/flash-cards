<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use Carbon\CarbonImmutable;

/**
 * One example of a corrected mistake on the finish of a round of hard words (docs/06 §14, {@see \App\Domain\Learning\Services\CorrectedMistakeFinder}):
 * a word the learner got wrong in an EARLIER session on an objective exercise and answered right, on an objective exercise, at its first
 * grade in this round.
 */
final readonly class CorrectedMistake
{
    /**
     * @param  string  $translation  in the learner's locale (WordTranslationResolver: the locale, its fallback, `words.translation`)
     * @param  CarbonImmutable  $mistakenAt  when the earlier wrong answer was given (UTC)
     * @param  string|null  $answer  what the learner TYPED then (spelling / gap-fill), only when it is safe to show: not empty and not the right answer
     */
    public function __construct(
        public int $wordId,
        public string $word,
        public string $translation,
        public CarbonImmutable $mistakenAt,
        public ?string $answer,
    ) {}

    /** @return array{word_id: int, word: string, translation: string, mistaken_at: string, answer: string|null} */
    public function toArray(): array
    {
        return [
            'word_id' => $this->wordId,
            'word' => $this->word,
            'translation' => $this->translation,
            'mistaken_at' => $this->mistakenAt->utc()->toIso8601String(),
            'answer' => $this->answer,
        ];
    }
}
