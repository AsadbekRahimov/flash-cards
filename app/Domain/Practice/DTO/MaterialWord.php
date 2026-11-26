<?php

declare(strict_types=1);

namespace App\Domain\Practice\DTO;

/**
 * A word as the modes see it (docs/16 §4.1): the English text, the base translation of `words.translation`, the audio
 * path, and the translation resolved for each locale of the {@see LocaleSet} (the requested locale, else the ru fallback -
 * WordTranslationResolver).
 */
final readonly class MaterialWord
{
    /**
     * @param  array<string, string>  $translations  locale => the translation shown in that locale
     * @param  string|null  $example  `words.example` - the game's `gap` task masks the word in it (docs/18 §12.3.1)
     * @param  int|null  $lexemeId  `words.lexeme_id` - the game puts it into the word tasks of a run's snapshot (the word points, docs/32 §3.6);
     *                              null for a word the catalog does not know and where the reader did not select it (distractors)
     * @param  bool  $phrase  a phrase card (`part_of_speech = phrase`, docs/35 §3.3): the game gives it no `spell`, its `gap` is the key word
     *                        in the phrase itself, and its distractors are phrases
     * @param  string|null  $phraseGap  the key word of a phrase (`meta.phrase.gap`); null for a word, and where the reader did not select it
     */
    public function __construct(
        public int $id,
        public int $lessonId,
        public string $word,
        public string $translation,
        public ?string $audioPath,
        public array $translations,
        public ?string $example = null,
        public ?int $lexemeId = null,
        public bool $phrase = false,
        public ?string $phraseGap = null,
    ) {}

    public function translationFor(string $locale): string
    {
        return $this->translations[$locale] ?? $this->translation;
    }
}
