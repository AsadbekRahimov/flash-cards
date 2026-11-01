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
    /** @param array<string, string> $translations locale => the translation shown in that locale */
    public function __construct(
        public int $id,
        public int $lessonId,
        public string $word,
        public string $translation,
        public ?string $audioPath,
        public array $translations,
    ) {}

    public function translationFor(string $locale): string
    {
        return $this->translations[$locale] ?? $this->translation;
    }
}
