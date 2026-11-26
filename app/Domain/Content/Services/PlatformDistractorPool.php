<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * The words every card and every run may borrow as distractors from the platform (docs/06 §12.2, docs/18 §12.3.1): the first
 * active words of the oldest published public courses of the platform ({@see Course::scopePublicPlatform()}), of one kind of
 * card ({@see Course::scopeOfCardKind()}): phrases for a phrase, words for a word. Private courses of centers and the decks of
 * learners are not in the query at all, so their words can never be an option on somebody else's screen.
 *
 * It is the same list for everybody, so it is read once and kept as plain rows in the pure cache (docs/19 §3), one entry per
 * kind. The readers ask {@see nearest()} for the lowest ids outside one word (the card) or one course (the course of the run) -
 * what the two former queries returned with `ORDER BY id LIMIT 40`, which read every word of every public course on each call
 * (33-43 ms at 60k public words, growing with them; docs/38, «Дополнение: варианты ответа»).
 *
 * What is kept: of each of the first {@see COURSES} courses (by id), the {@see PER_COURSE} lowest ids among the active words of
 * its first {@see LESSONS} lessons (in the order of the course); walked by id until the words outside the biggest course are
 * enough for a run of that course ({@see reduce()}). So an entry is about a hundred rows whatever the size of the platform, and
 * the query that fills it reads the lessons of {@see COURSES} courses and the words of {@see COURSES} x {@see LESSONS} lessons -
 * it does not grow with the words of the platform, the number of courses or the decks of learners.
 *
 * Dropped by {@see forget()} after whatever changes words, lessons, modules or a course (`LearningCache::forgetLessonId()`,
 * `CourseCatalogService::forgetCourseTree()`, the removal of a course); the TTL bounds what a lost `forget` leaves behind.
 */
final class PlatformDistractorPool
{
    /** The candidates a reader gets: the size of a layer of `ExercisePromptBuilder` and of `game.run.distractor_pool`. */
    public const SIZE = 40;

    /** Oldest courses of the kind looked at. Two are enough when a course has {@see SIZE} words; more for small courses. */
    private const COURSES = 8;

    /** First lessons of a course looked at (stage number, lesson number). */
    private const LESSONS = 5;

    /** Lowest ids kept of a course: {@see SIZE} and the card itself. */
    private const PER_COURSE = self::SIZE + 1;

    /** Ten minutes: a `forget` swallowed by a failing `redis-cache` leaves a removed word among the options no longer (docs/19 §3 rule 5). */
    private const TTL = 600;

    private const KEY = 'content:platform-distractors:v1:';

    /**
     * Up to `$limit` rows of the kind with the lowest ids, without the word `$exceptWordId` and without the words of the course
     * `$exceptCourseId`. No statement when the entry of the kind is in the cache, one when it is not. An entry answers for
     * {@see SIZE} rows; a bigger `$limit` gets what the entry happens to hold.
     *
     * @return list<array{id: int, lesson_id: int, course_id: int, word: string, translation: string, audio_path: string|null, example: string|null, part_of_speech: string|null}>
     */
    public function nearest(bool $phrases, int $limit = self::SIZE, ?int $exceptWordId = null, ?int $exceptCourseId = null): array
    {
        $rows = [];
        if ($limit <= 0) {
            return $rows;
        }

        foreach ($this->rows($phrases) as $row) {
            if ($row['id'] === $exceptWordId || $row['course_id'] === $exceptCourseId) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The entry of the kind, by id.
     *
     * @return list<array{id: int, lesson_id: int, course_id: int, word: string, translation: string, audio_path: string|null, example: string|null, part_of_speech: string|null}>
     */
    public function rows(bool $phrases): array
    {
        /** @var list<array{id: int, lesson_id: int, course_id: int, word: string, translation: string, audio_path: string|null, example: string|null, part_of_speech: string|null}> $rows */
        $rows = PureCache::store()->remember(self::key($phrases), self::TTL, fn (): array => self::reduce($this->load($phrases)));

        return $rows;
    }

    /** Both entries with one command; never throws (an invalidation must not fail the write it follows). */
    public static function forget(): void
    {
        PureCache::forgetMany(self::keys());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::key(false), self::key(true)];
    }

    public static function key(bool $phrases): string
    {
        return self::KEY.($phrases ? 'phrases' : 'words');
    }

    /**
     * ONE statement: the first lessons of the first courses of the kind, then the lowest ids of each course among their active
     * words. Every step goes by an index (courses by status / owner, `stages (course_id, number)`, `lessons (stage_id, number)`,
     * `words (lesson_id)`), and none of them depends on how many words, courses or decks there are beyond these courses.
     *
     * @return list<array{id: int, lesson_id: int, course_id: int, word: string, translation: string, audio_path: string|null, example: string|null, part_of_speech: string|null}>
     */
    private function load(bool $phrases): array
    {
        $courses = Course::query()->select('courses.id')->publicPlatform()->ofCardKind($phrases)->orderBy('courses.id')->limit(self::COURSES);

        $lessons = DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->whereIn('stages.course_id', $courses)
            ->select('lessons.id', 'stages.course_id')
            ->selectRaw('row_number() over (partition by stages.course_id order by stages.number, lessons.number) as lesson_rank');

        $words = DB::query()
            ->fromSub($lessons, 'first_lessons')
            ->join('words', 'words.lesson_id', '=', 'first_lessons.id')
            ->where('first_lessons.lesson_rank', '<=', self::LESSONS)
            ->where('words.is_active', true)
            ->select('words.id', 'words.lesson_id', 'first_lessons.course_id', 'words.word', 'words.translation', 'words.audio_path', 'words.example', 'words.part_of_speech')
            ->selectRaw('row_number() over (partition by first_lessons.course_id order by words.id) as word_rank');

        $rows = [];
        foreach (DB::query()->fromSub($words, 'ranked')->where('word_rank', '<=', self::PER_COURSE)->orderBy('id')->get() as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'lesson_id' => (int) $row->lesson_id,
                'course_id' => (int) $row->course_id,
                'word' => (string) $row->word,
                'translation' => (string) $row->translation,
                'audio_path' => $row->audio_path === null ? null : (string) $row->audio_path,
                'example' => $row->example === null ? null : (string) $row->example,
                'part_of_speech' => $row->part_of_speech === null ? null : (string) $row->part_of_speech,
            ];
        }

        return $rows;
    }

    /**
     * The shortest beginning of `$rows` (by id, no more than {@see PER_COURSE} of a course) that answers every reader: it is
     * cut where the words outside the course with the most rows reach {@see SIZE} - then a run of ANY course finds its
     * {@see SIZE} lowest ids of the other courses in it, and a card its {@see SIZE} lowest ids without itself. A row beyond
     * that point is in nobody's answer.
     *
     * @template TRow of array{id: int, course_id: int, ...}
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    public static function reduce(array $rows): array
    {
        $kept = [];
        $perCourse = [];
        $most = 0;
        foreach ($rows as $row) {
            $kept[] = $row;
            $perCourse[$row['course_id']] = ($perCourse[$row['course_id']] ?? 0) + 1;
            $most = max($most, $perCourse[$row['course_id']]);
            if (count($kept) - $most >= self::SIZE) {
                break;
            }
        }

        return $kept;
    }
}
