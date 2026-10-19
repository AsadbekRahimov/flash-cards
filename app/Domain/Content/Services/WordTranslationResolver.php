<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Word;
use App\Models\WordTranslation;

/**
 * The only reader of word translations (docs/12 §1.7, §3.2):
 * `word_translations[locale]` → `word_translations[fallback]` → `words.translation`
 * (the ru default). The fallback locale is `config('localization.fallback')`.
 *
 * "Fallback shown" means the text is not in the requested locale. Asking for
 * the fallback locale itself never counts as a fallback, even when the answer
 * comes from `words.translation`.
 */
final class WordTranslationResolver
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function for(Word $word, string $locale): string
    {
        return $this->resolve($word, $locale)->text;
    }

    public function resolve(Word $word, string $locale): ResolvedTranslation
    {
        if ($word->relationLoaded('translations')) {
            $rows = [];
            foreach ($word->translations as $row) {
                $rows[$row->locale] = $row->translation;
            }

            return $this->pick($word, $locale, $rows);
        }

        return $this->resolveMany([$word], $locale)[(int) $word->id];
    }

    /**
     * Batch lookup: at most two locales, one query, no N+1.
     *
     * @param  iterable<Word>  $words
     * @return array<int, ResolvedTranslation> word_id => translation
     */
    public function resolveMany(iterable $words, string $locale): array
    {
        $list = [];
        foreach ($words as $word) {
            $list[] = $word;
        }

        $ids = [];
        foreach ($list as $word) {
            $ids[(int) $word->id] = true;
        }

        $byWord = [];
        if ($ids !== []) {
            $rows = WordTranslation::query()
                ->whereIn('word_id', array_keys($ids))
                ->whereIn('locale', $this->chain($locale))
                ->get(['word_id', 'locale', 'translation']);

            foreach ($rows as $row) {
                $byWord[(int) $row->word_id][$row->locale] = $row->translation;
            }
        }

        $out = [];
        foreach ($list as $word) {
            $out[(int) $word->id] = $this->pick($word, $locale, $byWord[(int) $word->id] ?? []);
        }

        return $out;
    }

    /**
     * Batch lookup of plain strings.
     *
     * @param  iterable<Word>  $words
     * @return array<int, string> word_id => translation
     */
    public function map(iterable $words, string $locale): array
    {
        return array_map(
            static fn (ResolvedTranslation $t): string => $t->text,
            $this->resolveMany($words, $locale),
        );
    }

    /**
     * @param  array<string, string>  $rows  locale => translation of one word
     */
    private function pick(Word $word, string $locale, array $rows): ResolvedTranslation
    {
        $fallback = $this->locales->fallback();

        foreach ([$locale, $fallback] as $candidate) {
            $text = $rows[$candidate] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return new ResolvedTranslation($text, $candidate, $candidate !== $locale);
            }
        }

        return new ResolvedTranslation((string) $word->translation, $fallback, $locale !== $fallback);
    }

    /** @return list<string> */
    private function chain(string $locale): array
    {
        return array_values(array_unique([$locale, $this->locales->fallback()]));
    }
}
