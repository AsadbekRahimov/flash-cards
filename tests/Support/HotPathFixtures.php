<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Content\Services\CoursePublisher;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Catalog data of any size for the query-budget / equivalence tests of the hot paths.
 *
 * Words and progress rows go in by bulk INSERT: the Word factory draws from a finite dictionary
 * (`fake()->unique()`), so it cannot build the volumes these tests need.
 */
final class HotPathFixtures
{
    /**
     * A published course built from `stage number => [lesson number => active words | [active, inactive]]`.
     * Stages and lessons are created in the order of the array (a test may list stage 2 first), so the
     * ids do not follow the study order. `courses.words_count` is recounted the way every production
     * path does it (CoursePublisher::recount). `lessons` and `words` of the result are keyed
     * "stage.lesson" (e.g. "1.2").
     *
     * @param  array<int, array<int, int|array{0:int, 1:int}>>  $layout
     * @param  array<string, mixed>  $attributes  attributes of the course
     * @return array{course: Course, lessons: array<string, Lesson>, words: array<string, array{active: list<int>, inactive: list<int>}>}
     */
    public static function course(array $layout, array $attributes = []): array
    {
        $course = Course::factory()->published()->create($attributes);
        $lessons = [];
        $words = [];

        foreach ($layout as $stageNumber => $stageLessons) {
            $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => $stageNumber]);

            foreach ($stageLessons as $lessonNumber => $spec) {
                [$active, $inactive] = is_array($spec) ? $spec : [$spec, 0];
                $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $lessonNumber]);
                $key = $stageNumber.'.'.$lessonNumber;

                $lessons[$key] = $lesson;
                $words[$key] = self::words($lesson, $active, $inactive);
            }
        }

        app(CoursePublisher::class)->recount($course);

        return ['course' => $course->refresh(), 'lessons' => $lessons, 'words' => $words];
    }

    /**
     * @return array{active: list<int>, inactive: list<int>} the ids of the words, in insertion order
     */
    public static function words(Lesson $lesson, int $active, int $inactive = 0): array
    {
        $rows = [];
        for ($i = 1; $i <= $active + $inactive; $i++) {
            $rows[] = [
                'lesson_id' => $lesson->id,
                'word' => "w{$lesson->id}-{$i}",
                'translation' => "т{$lesson->id}-{$i}",
                'is_active' => $i <= $active,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('words')->insert($chunk);
        }

        /** @var list<int> $ids */
        $ids = DB::table('words')->where('lesson_id', $lesson->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return ['active' => array_slice($ids, 0, $active), 'inactive' => array_slice($ids, $active)];
    }

    /**
     * Gives the learner a progress row per word: `$repetitions` 0 is a card that was only SERVED,
     * 1 and more one that was answered at least once (the "learned" of the catalog).
     *
     * @param  iterable<int>  $wordIds
     */
    public static function learn(Learner|int $learner, iterable $wordIds, int $repetitions = 1): void
    {
        $learnerId = $learner instanceof Learner ? $learner->id : $learner;
        $rows = [];

        foreach ($wordIds as $wordId) {
            $rows[] = [
                'learner_id' => $learnerId,
                'word_id' => $wordId,
                'repetitions' => $repetitions,
                'interval_days' => $repetitions > 0 ? 6 : 0,
                'next_review_at' => now()->addDays(3),
                'state' => $repetitions > 0 ? 'review' : 'new',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('word_repetitions')->insert($chunk);
        }
    }

    /**
     * @param  array{active: list<int>, inactive: list<int>}  $words
     * @return list<int>
     */
    public static function all(array $words): array
    {
        return [...$words['active'], ...$words['inactive']];
    }

    /**
     * A small deterministic generator for randomised equivalence tests: `$rng(min, max)` is an
     * int in [min, max]. (A test must not reseed the global mt_rand that Faker draws from.)
     */
    public static function rng(int $seed): Closure
    {
        $state = $seed * 2654435761 % 2147483647;

        return function (int $min, int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return $min + (($state >> 8) % ($max - $min + 1));
        };
    }

    /**
     * @param  list<int>  $items
     * @return list<int> the items in a scrambled order (Fisher-Yates driven by $rng)
     */
    public static function scramble(array $items, Closure $rng): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $rng(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
