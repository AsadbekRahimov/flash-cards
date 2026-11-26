<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\LexemeBackfillReport;
use App\Domain\Content\Support\LexemeKey;
use App\Models\Lexeme;
use App\Models\Word;
use Illuminate\Support\Facades\DB;

/**
 * The catalog of lexemes (docs/32 §2.1): links `words.lexeme_id` to the lexeme of the word's spelling ({@see LexemeKey}).
 *
 *   ensure — the words of platform and organisation courses CREATE their lexeme (insert-or-ignore, then a select: two parallel
 *            writers of one key meet on the unique `(language, key)` and read the same row);
 *   none   — a word of a learner's deck (`courses.owner_type = learner`) is NEVER linked (owner's decision of 27.11.2026, docs/32 §2.1,
 *            §12.12): the learner writes its translation himself, so it earns no points of the rating - and, having no lexeme, its
 *            answer neither credits nor spends the attempt of the day of the same spelling in a course of the platform. The answer
 *            needs no statement to know it: `words.lexeme_id` IS NULL rides in the read of the card it already makes. Every writer of
 *            a deck word writes NULL ({@see clearFor()}, {@see LearnerDeckService}); `lexemes:backfill` unlinks a deck word that has
 *            a lexeme (the links made before the decision; the migration `2026_12_01_100001` did it once).
 *
 * Writers: the vocabulary import (and the course pack import through it), the admin panel's word create / edit
 * ({@see LessonContentRefresher}), the learner decks ({@see LearnerDeckService}, always NULL) and the backfill ({@see backfill()}).
 * The link is written with the query builder: it does not move `words.updated_at` (the word's content did not change), and a
 * link of the backfill is conditional on `lexeme_id IS NULL`, so it never overwrites a link a writer made meanwhile.
 */
final class LexemeCatalog
{
    public const DEFAULT_LANGUAGE = 'en';

    /** Keys per insert / select statement (two bound parameters a row: far below the PostgreSQL limit). */
    private const KEYS_PER_STATEMENT = 500;

    /** Whether the words of a course of this owner are linked to the catalog (platform, organisation) - a learner's deck never is. */
    public function createsLexemes(string $ownerType): bool
    {
        return $ownerType !== 'learner';
    }

    /**
     * Links a word of a platform or organisation course: the lexeme of its spelling is created when missing.
     *
     * @return int|null the lexeme id; null for a word without a key (blank)
     */
    public function ensureFor(Word $word, string $language = self::DEFAULT_LANGUAGE): ?int
    {
        $key = LexemeKey::of($word->word);
        $lexemeId = $key === null ? null : ($this->ensureKeys([$key], $language)[$key] ?? null);

        $this->write($word, $lexemeId);

        return $lexemeId;
    }

    /**
     * A word of a learner's deck: no lexeme, ever (see the class) - a link it has is cleared.
     */
    public function clearFor(Word $word): null
    {
        $this->write($word, null);

        return null;
    }

    /**
     * Links a word whose course is not in hand (the admin panel edits words of every course): one read of the course's owner
     * and language, then {@see ensureFor()} or {@see clearFor()}. A word without a lesson / course is left as it is.
     */
    public function syncFor(Word $word): ?int
    {
        $course = DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('courses', 'courses.id', '=', 'stages.course_id')
            ->where('lessons.id', $word->lesson_id)
            ->first(['courses.owner_type', 'courses.target_language']);

        if ($course === null) {
            return $word->lexeme_id;
        }

        $language = self::language($course->target_language);

        return $this->createsLexemes((string) $course->owner_type)
            ? $this->ensureFor($word, $language)
            : $this->clearFor($word);
    }

    /**
     * The lexemes of the keys, created when missing.
     *
     * @param  list<string>  $keys  normalised keys ({@see LexemeKey::of()})
     * @return array<string, int> key => lexeme id, every key
     */
    public function ensureKeys(array $keys, string $language = self::DEFAULT_LANGUAGE): array
    {
        return $this->resolve($keys, $language)[0];
    }

    /**
     * Writes the links of many words in one statement (`CASE id WHEN … THEN … END`, every value bound). Only the rows whose
     * link differs are touched.
     *
     * @param  array<int, int|null>  $links  word id => lexeme id
     * @param  bool  $onlyUnlinked  touch only words whose `lexeme_id` is still NULL (the backfill: a concurrent writer wins)
     * @return int rows changed
     */
    public function link(array $links, bool $onlyUnlinked = false): int
    {
        if ($links === []) {
            return 0;
        }

        $changed = 0;
        foreach (array_chunk($links, self::KEYS_PER_STATEMENT, preserve_keys: true) as $chunk) {
            $cases = [];
            $bindings = [];
            foreach ($chunk as $wordId => $lexemeId) {
                $cases[] = 'WHEN ? THEN CAST(? AS BIGINT)';
                $bindings[] = $wordId;
                $bindings[] = $lexemeId;
            }

            $ids = array_keys($chunk);
            $sql = 'UPDATE words SET lexeme_id = CASE id '.implode(' ', $cases).' END'
                .' WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')';
            $bindings = [...$bindings, ...$ids];

            if ($onlyUnlinked) {
                $sql .= ' AND lexeme_id IS NULL';
            }

            $changed += DB::update($sql, $bindings);
        }

        return $changed;
    }

    /**
     * `lexemes:backfill`: links the words of platform and organisation courses that have no lexeme yet, in batches by `words.id` (keyset,
     * each batch a few indexed statements, no long transaction), then unlinks every word of a learner's deck that has one (see the
     * class: a deck is outside the catalog). Idempotent: a second run changes nothing.
     *
     * @param  bool  $decksOnly  only the second pass (the unlinking of the deck words)
     * @param  (callable(string $phase, int $scanned): void)|null  $progress
     */
    public function backfill(int $batch = 1000, bool $decksOnly = false, ?callable $progress = null): LexemeBackfillReport
    {
        $report = new LexemeBackfillReport;
        $batch = max(1, $batch);

        if (! $decksOnly) {
            $this->backfillCatalog($batch, $report, $progress);
        }
        $report->deckWordsUnlinked = $this->unlinkDeckWords($batch, $progress);

        return $report;
    }

    /**
     * The words of learners' decks that still have a lexeme get NULL, in batches of `$batch` by `words.id` (the keyset over the deck
     * words with a link: lessons → stages → courses by their keys, `owner_type = learner`). Returns how many were unlinked.
     *
     * @param  (callable(string $phase, int $scanned): void)|null  $progress
     */
    public function unlinkDeckWords(int $batch = 1000, ?callable $progress = null): int
    {
        $batch = max(1, $batch);
        $after = 0;
        $unlinked = 0;

        do {
            $ids = DB::table('words')
                ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
                ->join('stages', 'stages.id', '=', 'lessons.stage_id')
                ->join('courses', 'courses.id', '=', 'stages.course_id')
                ->where('words.id', '>', $after)
                ->whereNotNull('words.lexeme_id')
                ->where('courses.owner_type', '=', 'learner')
                ->orderBy('words.id')
                ->limit($batch)
                ->pluck('words.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $after = $ids[count($ids) - 1];
            $unlinked += DB::table('words')->whereIn('id', $ids)->whereNotNull('lexeme_id')->update(['lexeme_id' => null]);

            if ($progress !== null) {
                $progress('decks', $unlinked);
            }
        } while (count($ids) === $batch);

        return $unlinked;
    }

    /** @param  (callable(string $phase, int $scanned): void)|null  $progress */
    private function backfillCatalog(int $batch, LexemeBackfillReport $report, ?callable $progress): void
    {
        $after = 0;
        $scanned = 0;

        do {
            $rows = DB::table('words')
                ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
                ->join('stages', 'stages.id', '=', 'lessons.stage_id')
                ->join('courses', 'courses.id', '=', 'stages.course_id')
                ->where('words.id', '>', $after)
                ->whereNull('words.lexeme_id')
                ->where('courses.owner_type', '<>', 'learner')
                ->orderBy('words.id')
                ->limit($batch)
                ->get(['words.id', 'words.word', 'courses.target_language']);

            if ($rows->isEmpty()) {
                break;
            }

            $after = (int) $rows->last()->id;
            $scanned += $rows->count();

            /** @var array<string, array<int, string>> $byLanguage language => word id => key */
            $byLanguage = [];
            foreach ($rows as $row) {
                $key = LexemeKey::of((string) $row->word);
                if ($key === null) {
                    $report->blank++;

                    continue;
                }
                $byLanguage[self::language($row->target_language ?? null)][(int) $row->id] = $key;
            }

            $links = [];
            foreach ($byLanguage as $language => $keysByWord) {
                [$lexemes, $created] = $this->resolve(array_values(array_unique($keysByWord)), $language);
                $report->lexemesCreated += $created;

                foreach ($keysByWord as $wordId => $key) {
                    if (isset($lexemes[$key])) {
                        $links[$wordId] = $lexemes[$key];
                    }
                }
            }

            $report->catalogWordsLinked += $this->link($links, onlyUnlinked: true);

            if ($progress !== null) {
                $progress('catalog', $scanned);
            }
        } while ($rows->count() === $batch);
    }

    /**
     * The lexemes of the keys, the missing ones created first.
     *
     * @param  list<string>  $keys
     * @return array{0: array<string, int>, 1: int} key => lexeme id, lexemes created
     */
    private function resolve(array $keys, string $language): array
    {
        // Sorted: two transactions inserting overlapping keys (two imports of different courses) take the unique entries in one
        // order and wait for each other instead of deadlocking.
        $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
        sort($keys, SORT_STRING);
        $found = [];
        $created = 0;

        foreach (array_chunk($keys, self::KEYS_PER_STATEMENT) as $chunk) {
            $now = now();
            $created += Lexeme::query()->insertOrIgnore(array_map(
                static fn (string $key): array => ['language' => $language, 'key' => $key, 'created_at' => $now],
                $chunk,
            ));

            foreach (Lexeme::query()->where('language', $language)->whereIn('key', $chunk)->pluck('id', 'key') as $key => $id) {
                $found[(string) $key] = (int) $id;
            }
        }

        return [$found, $created];
    }

    /**
     * Writes the link of one word when the ROW differs (the model in hand may be older than a backfill), and keeps the model in step
     * without marking it dirty.
     */
    private function write(Word $word, ?int $lexemeId): void
    {
        Word::query()->toBase()
            ->where('id', $word->id)
            ->where(fn ($query) => $lexemeId === null
                ? $query->whereNotNull('lexeme_id')
                : $query->whereNull('lexeme_id')->orWhere('lexeme_id', '<>', $lexemeId))
            ->update(['lexeme_id' => $lexemeId]);
        $word->setAttribute('lexeme_id', $lexemeId);
        $word->syncOriginalAttribute('lexeme_id');
    }

    /** The language of the lexemes of a course: its target language, `en` when it has none. */
    public static function language(mixed $language): string
    {
        return is_string($language) && $language !== '' ? $language : self::DEFAULT_LANGUAGE;
    }
}
