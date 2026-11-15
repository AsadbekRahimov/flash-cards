<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Learning\Services\LearningCache;
use App\Models\Word;
use App\Models\WordExample;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What an author edits by hand on the word screen of the admin panel besides the translations (docs/06 §12.3-§12.5): the
 * additional examples (`word_examples`) and the "confused with" list (`words.meta.confusable_with`). The file import writes
 * the same fields ({@see VocabularyImporter}); both drop the same caches after the commit: the examples of the word, and the
 * lesson's keys for the confusable list.
 *
 * Every write takes the word row first (`FOR UPDATE`), so two editors of one word cannot give two examples one position.
 */
final class WordContentEditor
{
    public const MAX_EXAMPLES = VocabularyImporter::MAX_EXAMPLES;

    public const MAX_CONFUSABLE = 10;

    public function __construct(private readonly LearningCache $cache) {}

    /**
     * Appends an example after the last one.
     *
     * @param  array<string, string|null>  $translations  locale => text; blank values are dropped
     */
    public function addExample(Word $word, string $text, array $translations): WordExample
    {
        $example = DB::transaction(function () use ($word, $text, $translations): WordExample {
            $this->lock($word);

            $last = WordExample::query()->where('word_id', $word->id)->max('position');
            if (WordExample::query()->where('word_id', $word->id)->count() >= self::MAX_EXAMPLES) {
                throw new LogicException('A word has at most '.self::MAX_EXAMPLES.' additional examples.');
            }

            return WordExample::query()->create([
                'word_id' => $word->id,
                'position' => ((int) $last) + 1,
                'text' => trim($text),
                'translations' => self::cleanTranslations($translations),
            ]);
        });

        $this->forgetExamples($word);

        return $example;
    }

    /** @param  array<string, string|null>  $translations */
    public function updateExample(WordExample $example, string $text, array $translations): WordExample
    {
        $word = Word::query()->findOrFail($example->word_id);

        DB::transaction(function () use ($word, $example, $text, $translations): void {
            $this->lock($word);
            $example->fill(['text' => trim($text), 'translations' => self::cleanTranslations($translations)])->save();
        });

        $this->forgetExamples($word);

        return $example;
    }

    /** Removes the example and closes the gap in the positions. */
    public function deleteExample(WordExample $example): void
    {
        $word = Word::query()->findOrFail($example->word_id);

        DB::transaction(function () use ($word, $example): void {
            $this->lock($word);
            $example->delete();

            $this->renumber($word, WordExample::query()->where('word_id', $word->id)->orderBy('position')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        });

        $this->forgetExamples($word);
    }

    /**
     * Puts the examples in the given order (ids of this word; any id the list misses keeps its place after the listed ones).
     *
     * @param  array<int|string>  $orderedIds
     */
    public function reorderExamples(Word $word, array $orderedIds): void
    {
        DB::transaction(function () use ($word, $orderedIds): void {
            $this->lock($word);

            /** @var list<int> $current */
            $current = WordExample::query()->where('word_id', $word->id)->orderBy('position')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $wanted = array_values(array_unique(array_map('intval', $orderedIds)));
            $listed = array_values(array_intersect($wanted, $current));

            $this->renumber($word, [...$listed, ...array_values(array_diff($current, $listed))]);
        });

        $this->forgetExamples($word);
    }

    /**
     * The tags of the "confused with" field as the content stores them: lower-cased, trimmed, unique, without the word itself,
     * at most {@see MAX_CONFUSABLE}.
     *
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    public function normalizeConfusable(Word $word, array $tags): array
    {
        $self = mb_strtolower(trim($word->word));
        $out = [];
        foreach ($tags as $tag) {
            $value = is_string($tag) ? mb_strtolower(trim($tag)) : '';
            if ($value !== '' && $value !== $self) {
                $out[$value] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::MAX_CONFUSABLE);
    }

    /**
     * The tags that name no word of this word's course (one statement). The import does not check this; the form does.
     *
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    public function unknownConfusable(Word $word, array $tags): array
    {
        $wanted = $this->normalizeConfusable($word, $tags);
        if ($wanted === []) {
            return [];
        }

        $lesson = $word->lesson()->with('stage:id,course_id')->first(['id', 'stage_id']);
        $courseId = $lesson?->stage?->course_id;
        if ($courseId === null) {
            return $wanted;
        }

        $found = DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $courseId)
            ->where(static function ($query) use ($wanted): void {
                foreach ($wanted as $tag) {
                    $query->orWhereRaw('lower(words.word) = ?', [$tag]);
                }
            })
            ->pluck('words.word')
            ->map(static fn (mixed $w): string => mb_strtolower(trim((string) $w)))
            ->all();

        return array_values(array_diff($wanted, $found));
    }

    /**
     * Writes `words.meta.confusable_with` (an empty list removes the mark, every other meta key stays). Returns true when the
     * stored list changed; then the lesson's cache keys are dropped after the commit, as the import does.
     *
     * @param  array<mixed>  $tags
     */
    public function saveConfusable(Word $word, array $tags): bool
    {
        $wanted = $this->normalizeConfusable($word, $tags);

        $changed = DB::transaction(function () use ($word, $wanted): bool {
            $this->lock($word);
            $word->refresh();

            if ($word->confusableWith() === $wanted) {
                return false;
            }

            /** @var array<string, mixed> $meta */
            $meta = is_array($word->getAttribute('meta')) ? $word->getAttribute('meta') : [];
            if ($wanted === []) {
                unset($meta['confusable_with']);
            } else {
                $meta['confusable_with'] = $wanted;
            }
            $word->forceFill(['meta' => $meta === [] ? null : $meta])->save();

            return true;
        });

        if ($changed) {
            $lessonId = (int) $word->lesson_id;
            DB::afterCommit(fn () => $this->cache->forgetLessonId($lessonId));
        }

        return $changed;
    }

    private function lock(Word $word): void
    {
        Word::query()->whereKey($word->id)->lockForUpdate()->value('id');
    }

    /**
     * Positions 1..n in the given order. Two passes (shift out of the way, then set): `(word_id, position)` is unique and
     * a one-statement renumbering would meet its own rows halfway.
     *
     * @param  array<int>  $orderedIds
     */
    private function renumber(Word $word, array $orderedIds): void
    {
        WordExample::query()->where('word_id', $word->id)->increment('position', 1000);

        foreach ($orderedIds as $i => $id) {
            WordExample::query()->where('word_id', $word->id)->whereKey($id)->update(['position' => $i + 1]);
        }
    }

    /** After the commit (also the outer one): a rolled-back edit must not drop the cache and re-warm it from stale rows. */
    private function forgetExamples(Word $word): void
    {
        $id = (int) $word->id;
        DB::afterCommit(fn () => $this->cache->forgetWordExamples([$id]));
    }

    /**
     * @param  array<string, string|null>  $translations
     * @return array<string, string>|null
     */
    private static function cleanTranslations(array $translations): ?array
    {
        $out = [];
        foreach ($translations as $locale => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[(string) $locale] = trim($text);
            }
        }

        return $out === [] ? null : $out;
    }
}
