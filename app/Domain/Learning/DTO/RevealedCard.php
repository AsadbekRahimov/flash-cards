<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\ExerciseType;

/**
 * What a meaning / reverse / audio card was sent without (it gives the right option away, docs/06 §12.2) and comes back
 * with the verdict of the answer: the card's screen after the pick. A phrase on gap_fill / pretest comes back too, for its
 * literal translation in `phrase` (docs/06 §16, {@see ExerciseType::revealsAfterAnswer()}).
 */
final readonly class RevealedCard
{
    /**
     * @param  string  $correct  the right option exactly as it stands among the options (reverse: the word; meaning / audio: the translation)
     * @param  string|null  $exampleAudioUrl  pre-generated mp3 of exactly `$example` (docs/26 §9.11); null when there is none
     * @param  array{word: string, translation: string|null}|null  $confusedWith
     * @param  array{kind: string|null, register: string|null, variant: string, literal: string|null}|null  $phrase  the labels of a phrase
     *                                                                                                               card with its literal translation (docs/35 §3.3 d); null for a word
     */
    public function __construct(
        public string $word,
        public string $translation,
        public string $correct,
        public ?string $example,
        public ?string $exampleTranslation,
        public ?string $transcription,
        public ?string $audioUrl,
        public ?string $hook,
        public ?string $note,
        public ?array $confusedWith,
        public ?string $exampleAudioUrl = null,
        public ?array $phrase = null,
    ) {}

    /**
     * `correct`: the right option exactly as it stands among the options - reverse: the word; meaning / audio / pretest: the
     * translation. A gap-fill of a phrase has no options: the whole phrase the gap was cut from.
     */
    public static function correctOf(ExerciseType $exercise, string $word, string $translation): string
    {
        return $exercise === ExerciseType::Reverse || $exercise === ExerciseType::GapFill ? trim($word) : trim($translation);
    }
}
