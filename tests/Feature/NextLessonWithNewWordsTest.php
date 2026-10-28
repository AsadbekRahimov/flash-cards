<?php

declare(strict_types=1);

/*
 * CourseCatalogService::nextLessonWithNewWords() — the lesson `GET /today` offers next (docs/12 §7.3): the first
 * lesson of the course in study order (stage.number, lesson.number) that has an ACTIVE word the learner was never
 * served (no `word_repetitions` row at all, whatever its state).
 *
 * The answers are pinned against `legacyNextLessonId()`, the original correlated-EXISTS query, so the lookup can be
 * rewritten for speed without changing a single answer.
 */

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HotPathFixtures as Fx;

uses(RefreshDatabase::class);

/** The implementation before the rewrite, verbatim: one correlated EXISTS per lesson. */
function legacyNextLessonId(Course $course, Learner $learner): ?int
{
    $lesson = Lesson::query()
        ->select('lessons.*')
        ->join('stages', 'stages.id', '=', 'lessons.stage_id')
        ->where('stages.course_id', $course->id)
        ->whereExists(fn (QueryBuilder $q): QueryBuilder => $q
            ->from('words')
            ->whereColumn('words.lesson_id', 'lessons.id')
            ->where('words.is_active', true)
            ->whereNotExists(fn (QueryBuilder $seen): QueryBuilder => $seen
                ->from('word_repetitions')
                ->whereColumn('word_repetitions.word_id', 'words.id')
                ->where('word_repetitions.learner_id', $learner->id)))
        ->orderBy('stages.number')
        ->orderBy('lessons.number')
        ->first();

    return $lesson?->id;
}

/** "stage.lesson" of the answer, null when there is none. */
function nextLessonKey(Course $course, Learner $learner): ?string
{
    $lesson = app(CourseCatalogService::class)->nextLessonWithNewWords($course, $learner);

    return $lesson === null ? null : $lesson->stage?->number.'.'.$lesson->number;
}

it('offers a newcomer the first lesson in study order, whatever the ids', function (): void {
    // Created in a scrambled order: the ids do not follow (stage.number, lesson.number).
    ['course' => $course] = Fx::course([2 => [1 => 3], 1 => [2 => 2, 1 => 4]]);

    expect(nextLessonKey($course, Learner::factory()->create()))->toBe('1.1');
});

it('stays on a lesson while one of its words was never served, then moves on', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 3, 2 => 2]]);
    $learner = Learner::factory()->create();

    Fx::learn($learner, array_slice($words['1.1']['active'], 0, 2));
    expect(nextLessonKey($course, $learner))->toBe('1.1');

    // Served but never answered (repetitions 0) is still "seen": the picker will not serve it as a new word.
    Fx::learn($learner, array_slice($words['1.1']['active'], 2), repetitions: 0);
    expect(nextLessonKey($course, $learner))->toBe('1.2');
});

it('crosses into the next stage and reports null once every active word was served', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 2], 2 => [1 => 1, 2 => 1]]);
    $learner = Learner::factory()->create();

    Fx::learn($learner, $words['1.1']['active']);
    expect(nextLessonKey($course, $learner))->toBe('2.1');

    Fx::learn($learner, $words['2.1']['active']);
    expect(nextLessonKey($course, $learner))->toBe('2.2');

    Fx::learn($learner, $words['2.2']['active']);
    expect(nextLessonKey($course, $learner))->toBeNull();
});

it('finds an earlier lesson the learner skipped over', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 2, 2 => 2, 3 => 2]]);
    $learner = Learner::factory()->create();

    Fx::learn($learner, [...$words['1.2']['active'], ...$words['1.3']['active'], $words['1.1']['active'][0]]);

    expect(nextLessonKey($course, $learner))->toBe('1.1');
});

it('does not count inactive words as new words', function (): void {
    // 1.1: one active word (learned) + two inactive ones never served; 1.2: only inactive words.
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => [1, 2], 2 => [0, 2], 3 => 1]]);
    $learner = Learner::factory()->create();
    Fx::learn($learner, $words['1.1']['active']);

    expect(nextLessonKey($course, $learner))->toBe('1.3');

    // An inactive word that comes back makes its lesson a candidate again.
    DB::table('words')->where('id', $words['1.2']['inactive'][0])->update(['is_active' => true]);
    expect(nextLessonKey($course, $learner))->toBe('1.2');
});

it('skips lessons without words and stages without lessons, and is null for an empty course', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 0, 2 => 0], 3 => [1 => 2]]);
    Stage::factory()->create(['course_id' => $course->id, 'number' => 2]); // a stage without lessons
    $learner = Learner::factory()->create();

    expect(nextLessonKey($course, $learner))->toBe('3.1');

    $empty = Course::factory()->published()->create();
    expect(nextLessonKey($empty, $learner))->toBeNull();

    Stage::factory()->create(['course_id' => $empty->id, 'number' => 1]);
    expect(nextLessonKey($empty, $learner))->toBeNull();
});

it('looks at this learner and this course only', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 2, 2 => 2]]);
    ['words' => $otherWords] = Fx::course([1 => [1 => 2]]);
    $learner = Learner::factory()->create();
    $classmate = Learner::factory()->create();

    // Somebody else has learned everything of this course, and this learner everything of another one.
    Fx::learn($classmate, [...$words['1.1']['active'], ...$words['1.2']['active']]);
    Fx::learn($learner, $otherWords['1.1']['active']);

    expect(nextLessonKey($course, $learner))->toBe('1.1')
        ->and(nextLessonKey($course, $classmate))->toBeNull();
});

it('returns the lesson with its stage loaded', function (): void {
    ['course' => $course] = Fx::course([4 => [7 => 1]]);

    $lesson = app(CourseCatalogService::class)->nextLessonWithNewWords($course, Learner::factory()->create());

    expect($lesson)->not->toBeNull()
        ->and($lesson?->relationLoaded('stage'))->toBeTrue()
        ->and($lesson?->stage?->number)->toBe(4)
        ->and($lesson?->number)->toBe(7)
        ->and($lesson?->getAttributes())->toHaveKeys(['id', 'stage_id', 'number', 'title']);
});

/** A small deterministic generator (a test must not reseed the global mt_rand that Faker draws from). */
function nextLessonRng(int $seed): Closure
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
function nextLessonScramble(array $items, Closure $rng): array
{
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = $rng(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }

    return $items;
}

it('answers exactly like the original query on random courses and progress', function (): void {
    $mismatches = [];
    $answers = [];

    foreach (range(1, 60) as $seed) {
        $rng = nextLessonRng($seed);

        // 1-3 stages, 1-4 lessons each, 0-3 active and 0-2 inactive words per lesson, created in a scrambled order.
        $layout = [];
        foreach (nextLessonScramble(range(1, $rng(1, 3)), $rng) as $stageNumber) {
            foreach (nextLessonScramble(range(1, $rng(1, 4)), $rng) as $lessonNumber) {
                $layout[$stageNumber][$lessonNumber] = [$rng(0, 3), $rng(0, 2)];
            }
        }
        ['course' => $course, 'words' => $words] = Fx::course($layout);

        $learner = Learner::factory()->create();
        foreach ($words as $lessonWords) {
            foreach (Fx::all($lessonWords) as $wordId) {
                $roll = $rng(1, 10);
                if ($roll <= 4) {
                    Fx::learn($learner, [$wordId], repetitions: 0); // served
                } elseif ($roll <= 8) {
                    Fx::learn($learner, [$wordId], repetitions: $rng(1, 5)); // answered
                } // else: never served
            }
        }

        $actual = app(CourseCatalogService::class)->nextLessonWithNewWords($course, $learner);
        $answers[] = $actual === null ? 'none' : $actual->stage?->number.'.'.$actual->number;
        if ($actual?->id !== legacyNextLessonId($course, $learner) || ($actual !== null && ! $actual->relationLoaded('stage'))) {
            $mismatches[] = $seed;
        }
    }

    expect($mismatches)->toBe([])
        // The generator is not degenerate: courses that are used up, and a spread of answers.
        ->and(in_array('none', $answers, true))->toBeTrue()
        ->and(count(array_unique($answers)))->toBeGreaterThanOrEqual(4);
});
