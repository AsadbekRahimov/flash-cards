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
use Tests\Support\QueryLog;

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

it('returns the same lesson and stage models a plain find would, with nothing of the join left in their attributes', function (): void {
    ['course' => $course, 'lessons' => $lessons] = Fx::course([4 => [7 => 1]]);

    $lesson = app(CourseCatalogService::class)->nextLessonWithNewWords($course, Learner::factory()->create());
    $plain = Lesson::query()->with('stage')->findOrFail($lessons['4.7']->id);

    // The stage comes from the columns of the joined row: if `stages` ever gets a column, this fails until the select follows.
    expect($lesson?->getAttributes())->toBe($plain->getAttributes())
        ->and($lesson?->stage?->getAttributes())->toBe($plain->stage?->getAttributes())
        ->and($lesson?->stage?->exists)->toBeTrue()
        ->and($lesson?->stage?->is($plain->stage))->toBeTrue()
        ->and(array_keys($lesson?->stage?->getAttributes() ?? []))->toBe(['id', 'course_id', 'number', 'title', 'description', 'created_at', 'updated_at'])
        ->and($lesson?->stage?->getDirty())->toBe([])
        ->and($lesson?->isDirty())->toBeFalse();
});

it('answers exactly like the original query on random courses and progress', function (): void {
    $mismatches = [];
    $answers = [];

    foreach (range(1, 60) as $seed) {
        $rng = Fx::rng($seed);

        // 1-3 stages, 1-4 lessons each, 0-3 active and 0-2 inactive words per lesson, created in a scrambled order.
        $layout = [];
        foreach (Fx::scramble(range(1, $rng(1, 3)), $rng) as $stageNumber) {
            foreach (Fx::scramble(range(1, $rng(1, 4)), $rng) as $lessonNumber) {
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

it('runs ONE statement however many lessons the course has or cards the learner holds', function (): void {
    // Worst case for the lookup: every lesson but the last one is used up, so the answer is the last lesson.
    $run = function (int $lessons, bool $usedUp): array {
        ['course' => $course, 'words' => $words] = Fx::course([1 => array_fill(1, $lessons, 3)]);
        $learner = Learner::factory()->create();
        if ($usedUp) {
            array_pop($words);
            Fx::learn($learner, array_merge(...array_map(fn (array $lessonWords): array => $lessonWords['active'], array_values($words))));
        }

        $answer = null;
        $queries = QueryLog::during(function () use ($course, $learner, &$answer): void {
            $answer = nextLessonKey($course, $learner);
        });

        return [$answer, $queries];
    };

    [$smallAnswer, $small] = $run(3, false);
    [$bigAnswer, $big] = $run(40, true);

    // The lesson and its stage come from one row: no second statement for `stages`.
    expect($smallAnswer)->toBe('1.1')
        ->and($bigAnswer)->toBe('1.40')
        ->and($small)->toHaveCount(1)
        ->and($big)->toHaveCount(1);

    // ONE statement over the words of the course: no correlated EXISTS per lesson any more.
    expect(QueryLog::matching($big, '/^select .* from ["`]lessons["`] inner join ["`]stages["`] .* inner join ["`]words["`] .* not exists \(select \* from ["`]word_repetitions["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($big, '/exists \(select \* from ["`]words["`]/'))->toBe([]);
});
