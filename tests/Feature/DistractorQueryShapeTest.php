<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamQuestionBuilder;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

/*
 * Shape of the rare slow distractor queries (measured on PostgreSQL, 100k words): the exercise "course" layer must not
 * walk the words primary key (`order by id + 0`), and the exam "foreign" layer must not sort every word of the platform
 * at random (two-step sample: lessons, then words of those lessons).
 */

uses(RefreshDatabase::class);

/** A lesson of a course (a new one unless given) with `$words` active words `<prefix>N`. */
function dqLesson(string $prefix, int $words, ?Course $course = null, array $courseAttributes = ['status' => 'published']): Lesson
{
    $course ??= Course::factory()->create($courseAttributes);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    for ($i = 0; $i < $words; $i++) {
        Word::factory()->for($lesson)->create(['word' => "{$prefix}{$i}", 'translation' => "{$prefix}-{$i}"]);
    }

    return $lesson;
}

/** @return list<Builder<Word>> */
function dqPromptLayers(Word $word): array
{
    $method = new ReflectionMethod(ExercisePromptBuilder::class, 'layers');

    return array_values(iterator_to_array($method->invoke(app(ExercisePromptBuilder::class), $word), false));
}

it('orders only the course layer of the exercise distractors by an expression the primary key cannot serve', function (): void {
    $own = dqLesson('own', 3);
    $course = $own->stage->course;
    dqLesson('sibling', 60, $course);
    dqLesson('foreign', 10);
    $word = Word::query()->where('lesson_id', $own->id)->orderBy('id')->firstOrFail();

    [$lessonLayer, $courseLayer, $platformLayer] = dqPromptLayers($word);

    expect($lessonLayer->toSql())->toMatch('/order by ["`]id["`] asc limit/i')
        ->and($courseLayer->toSql())->toContain('order by words.id + 0 limit')
        ->and($platformLayer->toSql())->toMatch('/order by ["`]id["`] asc limit/i');

    // Same rows as the plain id order: the 40 lowest ids of the course, minus the lesson and the word itself.
    $expected = Word::query()->where('is_active', true)->where('lesson_id', '<>', $own->id)
        ->whereIn('lesson_id', $course->stages()->join('lessons', 'lessons.stage_id', '=', 'stages.id')->pluck('lessons.id'))
        ->orderBy('id')->limit(40)->pluck('id')->all();

    expect($courseLayer->pluck('id')->all())->toBe($expected)->toHaveCount(40)
        ->and($courseLayer->pluck('id')->all())->not->toContain($word->id);
});

it('keeps inactive words and the lesson words out of the course layer', function (): void {
    $own = dqLesson('own', 2);
    $sibling = dqLesson('sibling', 3, $own->stage->course);
    Word::query()->where('lesson_id', $sibling->id)->orderBy('id')->firstOrFail()->update(['is_active' => false]);
    $word = Word::query()->where('lesson_id', $own->id)->orderBy('id')->firstOrFail();

    $ids = dqPromptLayers($word)[1]->pluck('id')->all();

    expect($ids)->toBe(Word::query()->where('lesson_id', $sibling->id)->where('is_active', true)->orderBy('id')->pluck('id')->all())
        ->toHaveCount(2);
});

it('runs the exam foreign layer as a lesson sample, never a random sort of every word', function (): void {
    $own = dqLesson('own', 4);
    foreach (range(1, 3) as $n) {
        dqLesson("f{$n}", 5);
    }
    Word::query()->update(['translation' => 'same']);
    Word::query()->where('lesson_id', '!=', $own->id)->get()->each(fn (Word $w) => $w->update(['translation' => "t{$w->id}"]));
    $words = Word::query()->where('lesson_id', $own->id)->get()->all();

    $queries = QueryLog::during(fn () => app(ExamQuestionBuilder::class)->forWords($words, $own->stage->course, 'ru'));

    $randomWords = QueryLog::matching($queries, '/from ["`]words["`].*order by random\(\)/i');
    expect($randomWords)->not->toBeEmpty();
    foreach ($randomWords as $sql) {
        // Only layers bounded by lessons / course: the two own-course layers and the sampled foreign lessons.
        expect($sql)->toMatch('/["`]lesson_id["`] in \(|["`]lessons["`]/i');
    }
    expect(QueryLog::matching($queries, '/^select ["`]lessons["`]\.["`]id["`] from ["`]lessons["`].*order by random\(\)/i'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select .* from ["`]words["`] where ["`]is_active["`] = \? and ["`]lesson_id["`] in \(.*order by random\(\)/i'))->toHaveCount(1);
});

it('borrows exam distractors only from active words of published public platform courses, not from the own course', function (): void {
    $own = dqLesson('own', 4);
    dqLesson('ownsib', 3, $own->stage->course);
    $public = dqLesson('pub', 6);
    $inactive = dqLesson('off', 2);
    Word::query()->where('lesson_id', $inactive->id)->update(['is_active' => false]);
    $outside = [
        dqLesson('draft', 3, null, ['status' => 'draft']),
        dqLesson('priv', 3, null, ['status' => 'published', 'visibility' => 'private']),
        dqLesson('org', 3, null, ['status' => 'published', 'owner_type' => 'organization']),
        dqLesson('deck', 3, null, ['status' => 'published', 'owner_type' => 'learner']),
    ];
    $emptyLesson = dqLesson('none', 0);
    Word::query()->where('lesson_id', $own->id)->update(['translation' => 'same']);
    $words = Word::query()->where('lesson_id', $own->id)->get()->all();

    $builder = app(ExamQuestionBuilder::class);
    $method = new ReflectionMethod($builder, 'foreignCandidates');

    for ($i = 0; $i < 5; $i++) {
        $got = array_column($method->invoke($builder, $own->stage->course_id, 40, 'ru'), 'translation');
        sort($got);
        expect($got)->toBe(['pub-0', 'pub-1', 'pub-2', 'pub-3', 'pub-4', 'pub-5']);
    }

    // And no exam option comes from outside the whitelist.
    foreach ($builder->forWords($words, $own->stage->course, 'ru') as $q) {
        foreach ($q['options'] as $option) {
            expect($option)->toMatch('/^(same|ownsib-\d|pub-\d)$/');
        }
    }
    expect($outside)->toHaveCount(4)->and($emptyLesson->words()->count())->toBe(0)->and($public->words()->count())->toBe(6);
});

it('gives an empty foreign layer without a words query when the platform has no foreign lessons', function (): void {
    $own = dqLesson('own', 4);
    dqLesson('draft', 5, null, ['status' => 'draft']);
    $builder = app(ExamQuestionBuilder::class);
    $method = new ReflectionMethod($builder, 'foreignCandidates');

    $queries = QueryLog::during(function () use ($method, $builder, $own, &$result): void {
        $result = $method->invoke($builder, $own->stage->course_id, 40, 'ru');
    });

    expect($result)->toBe([])
        ->and(QueryLog::matching($queries, '/^select [^(]*from ["`]words["`]/i'))->toBe([])
        ->and(QueryLog::matching($queries, '/^select ["`]lessons["`]\.["`]id["`] from ["`]lessons["`]/i'))->toHaveCount(1);
});

it('still builds the exam from its own course when the foreign layer is empty', function (): void {
    $own = dqLesson('own', 4);
    dqLesson('ownsib', 6, $own->stage->course);
    $words = Word::query()->where('lesson_id', $own->id)->get()->all();

    $questions = app(ExamQuestionBuilder::class)->forWords($words, $own->stage->course, 'ru');

    expect($questions)->toHaveCount(4);
    foreach ($questions as $q) {
        expect($q['options'])->toHaveCount(4);
    }
});
