<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\DTO\NewWordFrontierHint;
use App\Domain\Operations\Support\PureCache;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The "frontier" of a learner in a course: where the daily review's search for the next NEW word may start
 * (docs/06 §4.2, docs/19 §2.2). A pure cache on the evicting store ({@see PureCache}), one int per (learner, course):
 *
 *   - the id of the lesson in which the full candidate query (`TrainingScope::newWordCandidates()`, the anti-join over
 *     every word of the course, 5-11 ms on a course of 8k words) last found the first unseen active word, or
 *   - {@see NONE_LEFT} (0): it found none - the learner has a row for every active word of the course, or the course
 *     is not available to them.
 *
 * What is cached is a NARROWING of the search, never the decision (the exception to docs/19 §3 rule 9 the owner granted
 * on 13.11.2026): with a hint, CardPicker runs the same candidate query restricted to that one lesson, under the same
 * learner lock, with the same quota subquery; "this word is unseen and active, the course is available, the quota
 * allows it" is still answered by the database at that moment. A hint pointing at a lesson with nothing left, at a
 * lesson that no longer exists or belongs to another course yields no row, and the full query runs as before. A hint
 * can therefore only cost an extra statement, never serve a word twice or exceed the quota.
 *
 * Why the hint is exact (the invariant CardPicker relies on): "no lesson before the hinted one has an unseen active
 * word" stays true while the data version of the course ({@see CourseCatalogService::courseTreeVersionKey()}) is the
 * same, because (a) the application never deletes a learner's `word_repetitions` row - rows only go with the word or the
 * learner (FK cascade), and a deleted word is no candidate; (b) everything that can put an unseen active word into an
 * earlier lesson - a word added, re-activated or moved, a lesson or module created, renumbered or moved, the course's
 * status or visibility changed - raises the version (`CoursePublisher::recount()`, `transition()`, CourseTreeObserver,
 * `courses:recount-words`), and the version is part of the key; (c) a hint is read BEFORE the learner lock, but it is a
 * lower bound that only moves forward: whatever concurrent tab introduced words meanwhile made it more true, not less.
 * The key is built (the version read) before any row is read, so a pick that computed from rows older than a change
 * writes under a version nobody reads any more (docs/19 §3 rule 5, as the course tree).
 *
 * Should an invalidation ever be missed, the TTL bounds the damage: a lesson hint lives {@see TTL} seconds and the worst
 * case is a word of a later lesson introduced before one of an earlier lesson; {@see NONE_LEFT} lives
 * {@see TTL_NONE_LEFT} seconds only, because its worst case - "no new words" shown to a learner who has some - is
 * the one worth bounding tightly, and a learner who really finished the course costs one full query per five minutes.
 *
 * Decks of learners (`owner_type = learner`) are treated like any course: every path that writes their words ends in
 * `CoursePublisher::recount()` and raises the version, and finding out that a course is a deck would cost a read of
 * `courses` on every new card - the very thing the hint is there to save.
 *
 * Never throws: a pure cache that is unreachable or hung (docs/19 §4.1) degrades to "no hint" (the full query) and a
 * write that fails is reported and forgotten. No state is kept in the instance (Octane).
 */
final class NewWordFrontier
{
    /** The value of "nothing unseen is left in the course". */
    public const NONE_LEFT = 0;

    /** Thirty minutes, as the course tree (docs/19 K3): a wrong lesson only changes the order of introduction. */
    public const TTL = 1800;

    /** Five minutes: "nothing left" wrongly kept is the only outcome that hides words from a learner. */
    public const TTL_NONE_LEFT = 300;

    private const KEY_PREFIX = 'learning:frontier:v1:';

    /**
     * The hint for the pair, under the key of the course's CURRENT data version. Call it before the transaction of the
     * pick (no Redis round trip while the learner row is held) and keep the result to {@see write()} under the same key.
     *
     * @return NewWordFrontierHint|null null when the cache cannot be read: no hint, and nothing to write back either
     */
    public function read(int $learnerId, int $courseId): ?NewWordFrontierHint
    {
        try {
            $key = $this->key($learnerId, $courseId);
            $value = PureCache::store()->get($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return new NewWordFrontierHint($key, is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null);
    }

    /**
     * Records what the FULL query found, after the pick's transaction committed (never from inside it, and nothing
     * when it failed): the lesson of the first unseen active word - found or not served because of the quota, the
     * lesson is right either way - or {@see NONE_LEFT} when there was none.
     *
     * @param  int  $lessonId  the lesson, or {@see NONE_LEFT}
     */
    public function write(NewWordFrontierHint $hint, int $lessonId): void
    {
        try {
            PureCache::store()->put($hint->key, $lessonId, $lessonId === self::NONE_LEFT ? self::TTL_NONE_LEFT : self::TTL);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * `learning:frontier:v1:{learner}:{course}:n{version}` - the version is the course's data version on the
     * non-evicting default store (the same one the course tree is keyed with), read now.
     */
    public function key(int $learnerId, int $courseId): string
    {
        $version = (int) Cache::get(CourseCatalogService::courseTreeVersionKey($courseId), 0);

        return self::KEY_PREFIX."{$learnerId}:{$courseId}:n{$version}";
    }
}
