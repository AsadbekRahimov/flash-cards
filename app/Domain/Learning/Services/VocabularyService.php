<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Content\Services\WordTranslationResolver;
use App\Domain\Learning\DTO\VocabularyCursor;
use App\Domain\Learning\DTO\VocabularyPage;
use App\Domain\Learning\Enums\WordStage;
use App\Domain\Learning\Exceptions\InvalidVocabularyCursor;
use App\Domain\Learning\Support\MemoryStrength;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Learner;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * "My vocabulary" of the Mini App (docs/40 §2.3, docs/04 A.25): the learner's rows of `word_repetitions` graded at least once
 * (`last_reviewed_at IS NOT NULL`) over active words, in the alphabetical order of the word, with the memory strength of each
 * ({@see MemoryStrength}). Filters `all | weak (≤ 2) | strong (≥ 4)`, a search by the word and by the translation, keyset pages.
 *
 * Every statement starts from the learner's own rows (`word_repetitions` by `learner_id`, then `words` by its key, the translation row
 * by its unique `(word_id, locale)`): the search and the filter only narrow rows already picked, so no index of `words` is needed and
 * none is added (`LIKE '%q%'` could not use one anyway). Statements: the page; on the first page also the counts of the chips; the
 * translations of the page through {@see WordTranslationResolver} (cached per word). No cache of the list: personal, changed by every
 * grade, and cheap (docs/19 §3).
 */
final class VocabularyService
{
    public const FILTERS = ['all', 'weak', 'strong'];

    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly WordTranslationResolver $translations,
        private readonly WordAudioService $audio,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * @param  'all'|'weak'|'strong'  $filter
     * @param  string|null  $search  1–50 characters, already trimmed; null - no search
     * @param  string|null  $after  the cursor of the previous page; null - the first page (with the counts)
     *
     * @throws InvalidVocabularyCursor `$after` is not a cursor of the list
     */
    public function page(Learner $learner, string $filter = 'all', ?string $search = null, ?string $after = null, int $limit = self::PAGE_SIZE, ?CarbonImmutable $now = null): VocabularyPage
    {
        $now ??= CarbonImmutable::now();
        $limit = max(1, min(self::PAGE_SIZE, $limit));

        $cursor = null;
        if ($after !== null) {
            $cursor = VocabularyCursor::decode($after) ?? throw new InvalidVocabularyCursor('The cursor is not one of this list.');
        }

        $locale = $this->locales->forLearner($learner);
        $strength = MemoryStrength::sqlCase();

        $query = $this->rows($learner)
            ->select(['wr.id', 'wr.word_id', 'wr.state', 'wr.interval_days', 'wr.next_review_at', 'wr.is_hard', 'wr.last_quality',
                'w.word', 'w.transcription', 'w.audio_path', 'w.translation'])
            ->selectRaw('lower(w.word) as sort_word')
            ->selectRaw($strength['sql'].' as strength', $strength['bindings']);

        $condition = match ($filter) {
            'weak' => MemoryStrength::weakCondition(),
            'strong' => MemoryStrength::strongCondition(),
            default => null,
        };
        if ($condition !== null) {
            $query->whereRaw($condition['sql'], $condition['bindings']);
        }

        if ($search !== null && $search !== '') {
            $like = '%'.self::escapeLike(mb_strtolower($search)).'%';
            // The translation of the learner's language is only a FILTER here: the text shown comes from the resolver (docs/03 §2.21).
            $query->leftJoin('word_translations as wt', fn (JoinClause $join) => $join->on('wt.word_id', '=', 'w.id')->where('wt.locale', $locale))
                ->whereRaw("(lower(w.word) LIKE ? ESCAPE '\\' OR lower(w.translation) LIKE ? ESCAPE '\\' OR lower(wt.translation) LIKE ? ESCAPE '\\')", [$like, $like, $like]);
        }

        if ($cursor !== null) {
            $query->whereRaw('(lower(w.word), wr.id) > (?, ?)', [$cursor->word, $cursor->id]);
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderByRaw('lower(w.word)')->orderBy('wr.id')->limit($limit + 1)->get()->all();

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = (new VocabularyCursor((string) $last->sort_word, (int) $last->id))->encode();
        }

        return new VocabularyPage($this->items($rows, $locale, $now), $next, $cursor === null ? $this->counts($learner) : null);
    }

    /** The learner's graded rows over active words: what every statement of the list starts from. */
    private function rows(Learner $learner): Builder
    {
        return DB::table('word_repetitions as wr')
            ->join('words as w', fn (JoinClause $join) => $join->on('w.id', '=', 'wr.word_id')->where('w.is_active', true))
            ->where('wr.learner_id', $learner->id)
            ->whereNotNull('wr.last_reviewed_at');
    }

    /**
     * The chips: every graded word, the weak ones, the strong ones - one statement, independent of the search (as in the kit).
     *
     * @return array{all: int, weak: int, strong: int}
     */
    private function counts(Learner $learner): array
    {
        $weak = MemoryStrength::weakCondition();
        $strong = MemoryStrength::strongCondition();

        /** @var stdClass|null $row */
        $row = $this->rows($learner)
            ->selectRaw('count(*) as all_count')
            ->selectRaw('sum(case when '.$weak['sql'].' then 1 else 0 end) as weak_count', $weak['bindings'])
            ->selectRaw('sum(case when '.$strong['sql'].' then 1 else 0 end) as strong_count', $strong['bindings'])
            ->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'weak' => (int) ($row->weak_count ?? 0),
            'strong' => (int) ($row->strong_count ?? 0),
        ];
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<array{word_id: int, word: string, translation: string, translation_fallback: bool, transcription: string|null, audio_url: string|null, strength: int<0, 5>, stage: string, next_in_days: int|null, is_hard: bool}>
     */
    private function items(array $rows, string $locale, CarbonImmutable $now): array
    {
        $words = [];
        $repetitions = [];
        foreach ($rows as $row) {
            $words[(int) $row->id] = (new Word)->newFromBuilder([
                'id' => (int) $row->word_id,
                'word' => $row->word,
                'translation' => $row->translation,
                'transcription' => $row->transcription,
                'audio_path' => $row->audio_path,
            ]);
            $repetitions[(int) $row->id] = (new WordRepetition)->newFromBuilder([
                'state' => $row->state,
                'interval_days' => $row->interval_days,
                'last_quality' => $row->last_quality,
                'next_review_at' => $row->next_review_at,
            ]);
        }

        // ONE batch for the page (cached per word): the text in the learner's language, or the ru fallback with the flag.
        $translated = $words === [] ? [] : $this->translations->resolveMany(array_values($words), $locale);

        $items = [];
        foreach ($rows as $row) {
            $word = $words[(int) $row->id];
            $repetition = $repetitions[(int) $row->id];
            $translation = $translated[(int) $word->id] ?? null;
            /** @var int<0, 5> $strength */
            $strength = max(0, min(MemoryStrength::MAX, (int) $row->strength));

            $items[] = [
                'word_id' => (int) $word->id,
                'word' => (string) $word->word,
                'translation' => $translation->text ?? (string) $word->translation,
                'translation_fallback' => $translation->isFallback ?? false,
                'transcription' => $word->transcription,
                'audio_url' => $this->audio->urlFor($word),
                'strength' => $strength,
                'stage' => WordStage::of($repetition)->value,
                'next_in_days' => MemoryStrength::nextInDays($repetition->state, $repetition->next_review_at, $now),
                'is_hard' => (bool) $row->is_hard,
            ];
        }

        return $items;
    }

    /** `%`, `_` and the escape character itself match literally (`ESCAPE '\'`). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
