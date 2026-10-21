<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\ExerciseType;

/**
 * What the client shows for the exercise of a card (docs/13 §2.5, docs/04 A.3 `card.prompt`).
 */
final readonly class ExercisePrompt
{
    /**
     * @param  string  $front  flash: the word; reverse / spelling / gap_fill: the translation; audio: '' — the word is only heard
     * @param  string|null  $audioUrl  pre-generated mp3 of the word (the same as `card.audio_url`)
     * @param  string|null  $maskedExample  gap_fill: the example with the word's form replaced by `____`
     * @param  list<string>|null  $options  reverse / audio: the four choices (the right one among them, in a stable order)
     * @param  int|null  $answerLength  spelling / gap_fill: length of the expected text (a hint for the input)
     */
    public function __construct(
        public ExerciseType $type,
        public string $front,
        public ?string $audioUrl = null,
        public ?string $maskedExample = null,
        public ?array $options = null,
        public ?int $answerLength = null,
    ) {}

    public static function flash(string $word, ?string $audioUrl): self
    {
        return new self(ExerciseType::Flash, $word, $audioUrl);
    }
}
