<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
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
 *
 * The rows of `word_translations` are read through the pure cache (docs/19 K4): per word, locale and fallback, one `Cache::many` for a
 * whole batch and one query plus one `Cache::putMany` for the words it has not seen; a word with no rows is an entry too (an
 * empty array). Only the ROWS are cached - the legacy `words.translation` is always the live attribute of the Word in hand -
 * so an edit of that column shows at once, and an edit of a row is dropped by {@see forget()}, which the WordTranslation
 * observer calls after every save and delete (the manual writer, the importer, the decks).
 *
 * The fallback is in the key because the entry IS made of the two locales (`[locale, fallback]`): were `localization.fallback`
 * changed, an entry written under the old one would lack the row of the new one and the text would silently be the legacy column.
 * The entries of the old fallback are never read again and expire; {@see forget()} drops those of the fallback in force.
 */
final class WordTranslationResolver
{
    /** `word:tr:v1:{word id}:{locale}:{fallback}`: the rows the locale and the fallback read for the word, `locale => text`. */
    private const CACHE_PREFIX = 'word:tr:v1:';

    private const CACHE_TTL = 7200;

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

        $byWord = $ids === [] ? [] : $this->rowsOf(array_keys($ids), $locale);

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
     * Drops the cached rows of the words (every locale, under the fallback in force), after the commit of whatever changed their
     * translations. ONE call to the store for all the words, each of them once: the observer gathers a whole import into it.
     *
     * @param  list<int>  $wordIds
     */
    public function forget(array $wordIds): void
    {
        $fallback = $this->locales->fallback();
        $locales = $this->locales->supported();

        $keys = [];
        foreach (array_unique($wordIds) as $wordId) {
            foreach ($locales as $locale) {
                $keys[] = self::cacheKey($wordId, $locale, $fallback);
            }
        }

        if ($keys !== []) {
            PureCache::store()->deleteMultiple($keys);
        }
    }

    public static function cacheKey(int $wordId, string $locale, string $fallback): string
    {
        return self::CACHE_PREFIX."{$wordId}:{$locale}:{$fallback}";
    }

    /**
     * The translation rows of the words for `$locale` and its fallback: from the cache with ONE `many`, the rest from ONE query
     * and written back with ONE `putMany`.
     *
     * @param  list<int>  $wordIds
     * @return array<int, array<string, string>> word id => `locale => translation`
     */
    private function rowsOf(array $wordIds, string $locale): array
    {
        $cache = PureCache::store();
        $fallback = $this->locales->fallback();
        $keys = [];
        foreach ($wordIds as $wordId) {
            $keys[$wordId] = self::cacheKey($wordId, $locale, $fallback);
        }

        $cached = $cache->many(array_values($keys));

        $byWord = [];
        $missing = [];
        foreach ($keys as $wordId => $key) {
            $hit = $cached[$key] ?? null;

            if (is_array($hit)) {
                /** @var array<string, string> $hit */
                $byWord[$wordId] = $hit;
            } else {
                $missing[] = $wordId;
            }
        }

        if ($missing === []) {
            return $byWord;
        }

        $loaded = [];
        $rows = WordTranslation::query()
            ->whereIn('word_id', $missing)
            ->whereIn('locale', $this->chain($locale))
            ->get(['word_id', 'locale', 'translation']);

        foreach ($rows as $row) {
            $loaded[(int) $row->word_id][$row->locale] = $row->translation;
        }

        $fresh = [];
        foreach ($missing as $wordId) {
            $byWord[$wordId] = $loaded[$wordId] ?? [];
            $fresh[$keys[$wordId]] = $byWord[$wordId];
        }
        $cache->putMany($fresh, self::CACHE_TTL);

        return $byWord;
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
