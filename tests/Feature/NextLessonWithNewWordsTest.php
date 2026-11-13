<?php

declare(strict_types=1);

/*
 * CourseCatalogService::todayCourse()->nextLesson — the lesson `GET /today` offers next (docs/12 §7.3): the first
 * lesson of the course in study order (stage.number, lesson.number) that has an ACTIVE word the learner was never
 * served (no `word_repetitions` row at all, whatever its state).
 *
 * The answers are pinned against `legacyNextLessonId()`, the original correlated-EXISTS query, so the lookup can be
 * rewritten for speed without changing a single answer. The same call carries the course progress, pinned against
 * `listing()`. It is ONE query of the learner's rows beside the cached course tree.
 */

use App\Domain\Content\DTO\LearnerProgress;
use App\Domain\Content\DTO\NextLesson;
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
    $lesson = app(CourseCatalogService::class)->todayCourse($course, $learner)->nextLesson;

    return $lesson === null ? null : $lesson->stageNumber.'.'.$lesson->number;
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
    app(CourseCatalogService::class)->forgetCourseTree($course->id); // what changing a word does after commit
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

it('describes the lesson from the course tree: id, stage and lesson number, title in the learner\'s language', function (): void {
    ['course' => $course, 'lessons' => $lessons] = Fx::course([4 => [7 => 1]]);
    $lessons['4.7']->update(['title' => 'Приветствия', 'title_uz' => 'Salomlashish']);

    $ru = app(CourseCatalogService::class)->todayCourse($course, Learner::factory()->create(['interface_locale' => 'ru']));
    $uz = app(CourseCatalogService::class)->todayCourse($course, Learner::factory()->create(['interface_locale' => 'uz']));

    expect($ru->nextLesson)->toEqual(new NextLesson($lessons['4.7']->id, 4, 7, 'Приветствия'))
        ->and($uz->nextLesson?->title)->toBe('Salomlashish')
        ->and($ru->progress)->toEqual(new LearnerProgress(0, 1));
});

it('reads the next lesson of a learner\'s own deck from its freshly built tree', function (): void {
    $learner = Learner::factory()->create();
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 1, 2 => 1]], ['owner_type' => 'learner', 'owner_id' => $learner->id, 'visibility' => 'private']);

    expect(nextLessonKey($course, $learner))->toBe('1.1');

    Fx::learn($learner, $words['1.1']['active']);
    expect(nextLessonKey($course, $learner))->toBe('1.2');
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

        $catalog = app(CourseCatalogService::class);
        $today = $catalog->todayCourse($course, $learner);
        $actual = $today->nextLesson;
        $answers[] = $actual === null ? 'none' : $actual->stageNumber.'.'.$actual->number;
        if ($actual?->id !== legacyNextLessonId($course, $learner) || $today->progress != $catalog->listing($course, $learner)->progress) {
            $mismatches[] = $seed;
        }
    }

    expect($mismatches)->toBe([])
        // The generator is not degenerate: courses that are used up, and a spread of answers.
        ->and(in_array('none', $answers, true))->toBeTrue()
        ->and(count(array_unique($answers)))->toBeGreaterThanOrEqual(4);
});

it('runs ONE statement of the learner\'s rows however many lessons the course has or cards the learner holds', function (): void {
    // Worst case for the lookup: every lesson but the last one is used up, so the answer is the last lesson.
    $run = function (int $lessons, bool $usedUp): array {
        ['course' => $course, 'words' => $words] = Fx::course([1 => array_fill(1, $lessons, 3)]);
        $learner = Learner::factory()->create();
        if ($usedUp) {
            array_pop($words);
            Fx::learn($learner, array_merge(...array_map(fn (array $lessonWords): array => $lessonWords['active'], array_values($words))));
        }
        nextLessonKey($course, $learner); // warms the course tree

        $answer = null;
        $queries = QueryLog::during(function () use ($course, $learner, &$answer): void {
            $answer = nextLessonKey($course, $learner);
        });

        return [$answer, $queries];
    };

    [$smallAnswer, $small] = $run(3, false);
    [$bigAnswer, $big] = $run(40, true);

    expect($smallAnswer)->toBe('1.1')
        ->and($bigAnswer)->toBe('1.40')
        ->and($small)->toHaveCount(1)
        ->and($big)->toHaveCount(1);

    // The learner's own rows grouped by lesson: no anti-join over the words of the course, no read of lessons / stages.
    expect(QueryLog::matching($big, '/^select .* from ["`]word_repetitions["`] inner join ["`]words["`] .* group by ["`]words["`]\.["`]lesson_id["`]/s'))->toHaveCount(1)
        ->and(QueryLog::matching($big, '/not exists|from ["`]lessons["`]/'))->toBe([]);
});
