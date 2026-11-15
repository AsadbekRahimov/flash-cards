<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Word;
use App\Models\WordTranslation;

/**
 * The authored memory hook of a word in the learner's language (`word_translations.hook`, docs/25 §3.4, docs/06 §12.3).
 *
 * No fallback across languages: a sound association written for Russian is noise for an Uzbek reader, so the hook of the
 * requested locale is the only one shown (a morphological cue an author wants in both languages is written twice).
 * The hooks of a word (every locale) are one cache entry, `word:hook:v1:{id}`; it is read only for the cards that show a
 * hook (a new word, a failed one, a leech — {@see TrainingFlowService}), so the usual review
 * costs nothing here. Dropped by {@see forget()} from the WordTranslation observer after every save or delete of a row.
 */
final class WordHookResolver
{
    private const CACHE_PREFIX = 'word:hook:v1:';

    private const CACHE_TTL = 7200;

    public function for(Word $word, string $locale): ?string
    {
        if ($word->relationLoaded('translations')) {
            foreach ($word->translations as $row) {
                if ($row->locale === $locale) {
                    return self::clean($row->hook);
                }
            }

            return null;
        }

        /** @var array<string, string> $hooks */
        $hooks = PureCache::store()->remember(
            self::cacheKey((int) $word->id),
            self::CACHE_TTL,
            static function () use ($word): array {
                $out = [];
                $rows = WordTranslation::query()->where('word_id', $word->id)->whereNotNull('hook')->get(['locale', 'hook']);
                foreach ($rows as $row) {
                    $hook = self::clean($row->hook);
                    if ($hook !== null) {
                        $out[$row->locale] = $hook;
                    }
                }

                return $out;
            },
        );

        return $hooks[$locale] ?? null;
    }

    /** @param  list<int>  $wordIds */
    public function forget(array $wordIds): void
    {
        $keys = array_map(static fn (int $id): string => self::cacheKey($id), array_values(array_unique($wordIds)));
        if ($keys !== []) {
            PureCache::forgetMany($keys);
        }
    }

    public static function cacheKey(int $wordId): string
    {
        return self::CACHE_PREFIX.$wordId;
    }

    private static function clean(?string $hook): ?string
    {
        $text = trim((string) $hook);

        return $text === '' ? null : $text;
    }
}
