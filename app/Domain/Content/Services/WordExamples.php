<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Learning\Support\LadderPolicy;
use App\Models\Word;
use App\Models\WordRepetition;

/**
 * Which example a card shows (docs/25 §3.5, docs/06 §12.4).
 *
 * Until the card graduates the learner always sees the anchor (`words.example`): one context to tie the word to. After
 * graduation the anchor and the rows of `word_examples` rotate with the pass count — index `repetitions mod n` — so two
 * consecutive reviews show different sentences and a gap-fill is cut from a sentence other than the one the learner just
 * read on the previous review. Deterministic: the same (word, repetition row) always gives the same example, which is
 * what lets `POST /review` grade a gap-fill against the sentence `/next` served (docs/13 §8.2).
 *
 * Without additional examples the behaviour is the one before this class: the anchor, always. The rows come from the pure
 * cache ({@see LearningCache::wordExamples()}); a card that has not graduated never reads them.
 */
final class WordExamples
{
    public function __construct(
        private readonly LearningCache $cache,
        private readonly LadderPolicy $policy,
    ) {}

    /** The English sentence the card shows this time; null when the word has no example at all. */
    public function pick(Word $word, WordRepetition $repetition): ?string
    {
        return $this->pickRow($word, $repetition)['text'];
    }

    /**
     * The example and its translation in a locale (the translation of the anchor is not read here: it lives in
     * `word_translations.example_translation` and is not part of the card today).
     *
     * @return array{text: string|null, translation: string|null}
     */
    public function pickRow(Word $word, WordRepetition $repetition, ?string $locale = null): array
    {
        $anchor = self::text($word->example);

        if (! $this->policy->rotateExamples || $repetition->state !== RepetitionState::Review) {
            return ['text' => $anchor, 'translation' => null];
        }

        $extra = $this->cache->wordExamples((int) $word->id);
        if ($extra === []) {
            return ['text' => $anchor, 'translation' => null];
        }

        $all = [];
        if ($anchor !== null) {
            $all[] = ['text' => $anchor, 'translations' => []];
        }
        foreach ($extra as $row) {
            if (self::text($row['text']) !== null) {
                $all[] = $row;
            }
        }
        if ($all === []) {
            return ['text' => null, 'translation' => null];
        }

        $row = $all[((int) $repetition->repetitions) % count($all)];

        return [
            'text' => $row['text'],
            'translation' => $locale === null ? null : ($row['translations'][$locale] ?? null),
        ];
    }

    private static function text(?string $raw): ?string
    {
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }
}
