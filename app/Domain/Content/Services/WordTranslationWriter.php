<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manual edits of translations (admin panel). Keeps the two homes of the ru text in step:
 * `words.translation` (the default / last-resort fallback) and the `ru` row of
 * `word_translations` (what {@see WordTranslationResolver} reads first).
 *
 * The importer has its own bulk path and does the same on its own.
 */
final class WordTranslationWriter
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * Creates or updates the translation of one locale.
     *
     * A changed text becomes a `manual` row and loses its `reviewed_at` mark (the new text has not been
     * checked by a native speaker). Saving the `ru` row also rewrites `words.translation`.
     *
     * @throws InvalidArgumentException for a locale outside `config('localization.supported')`
     */
    public function save(Word $word, string $locale, string $translation, ?string $exampleTranslation = null): WordTranslation
    {
        if (! $this->locales->isSupported($locale)) {
            throw new InvalidArgumentException("Unsupported locale '{$locale}'.");
        }

        return DB::transaction(function () use ($word, $locale, $translation, $exampleTranslation): WordTranslation {
            $row = WordTranslation::query()->firstOrNew(['word_id' => $word->id, 'locale' => $locale]);

            $textChanged = ! $row->exists || $row->translation !== $translation;

            $row->translation = $translation;
            $row->example_translation = $exampleTranslation;
            if ($textChanged) {
                $row->source = 'manual';
                $row->reviewed_at = null;
            }
            $row->save();

            if ($locale === $this->locales->fallback() && $word->translation !== $translation) {
                $word->forceFill(['translation' => $translation])->save();
            }

            return $row;
        });
    }

    /**
     * After `words.translation` was edited directly (lesson words screen): mirror it into the ru row.
     */
    public function syncDefaultFromWord(Word $word): void
    {
        $locale = $this->locales->fallback();

        $row = WordTranslation::query()->where('word_id', $word->id)->where('locale', $locale)->first();
        if ($row !== null && $row->translation === $word->translation) {
            return;
        }

        $this->save($word, $locale, (string) $word->translation, $row?->example_translation);
    }

    public function markReviewed(WordTranslation $translation): void
    {
        $translation->forceFill(['reviewed_at' => now()])->save();
    }
}
