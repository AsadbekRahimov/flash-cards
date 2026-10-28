<?php

declare(strict_types=1);

use App\Domain\Content\DTO\ResolvedTranslation;
use App\Domain\Learning\DTO\ExercisePrompt;
use App\Domain\Learning\Enums\ExerciseType;
use App\Domain\Learning\Exceptions\ExamSessionException;
use App\Domain\Learning\Services\ExamQuestionBuilder;
use App\Domain\Learning\Services\ExercisePromptBuilder;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * One whitelist of foreign courses whose words may be borrowed as distractors: `Course::publicPlatform()`
 * (published + public + owned by the platform). The scope itself, and the three places that use it: the lesson exam
 * (ExamQuestionBuilder::build), the personal mock exam (::forWords) and the reverse exercise (ExercisePromptBuilder).
 */

uses(RefreshDatabase::class);

dataset('courses outside the distractor whitelist', [
    'draft' => ['draft', 'public', 'platform'],
    'review' => ['review', 'public', 'platform'],
    'archived' => ['archived', 'public', 'platform'],
    'published but private' => ['published', 'private', 'platform'],
    'published but unlisted' => ['published', 'unlisted', 'platform'],
    'published public, an organization course' => ['published', 'public', 'organization'],
    'published public, a learner deck' => ['published', 'public', 'learner'],
]);

/** A course with one stage and one lesson of `$words` active words named `<prefix>N` (translation `<prefix>-N`). */
function dwCourse(array $attributes, string $prefix, int $words): Lesson
{
    $course = Course::factory()->create($attributes);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    for ($i = 0; $i < $words; $i++) {
        Word::factory()->for($lesson)->create(['word' => "{$prefix}{$i}", 'translation' => "{$prefix}-{$i}"]);
    }

    return $lesson;
}

/**
 * An own course whose four words share ONE translation (no real distractor inside it) and a foreign course of ten
 * words: only the foreign course can supply options for the exams.
 *
 * @return array{course: Course, lesson: Lesson, words: list<Word>}
 */
function dwExamWorld(array $foreign): array
{
    $course = Course::factory()->published()->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    /** @var list<Word> $words */
    $words = Word::factory()->count(4)->for($lesson)->create(['translation' => 'same'])->all();
    dwCourse($foreign, 'foreign', 10);

    return ['course' => $course, 'lesson' => $lesson, 'words' => $words];
}

/** The reverse prompt of the only word of a lonely course; the foreign course of ten words is its only source of options. */
function dwReversePrompt(array $foreign): ExercisePrompt
{
    $lonely = dwCourse(['status' => 'published'], 'lonely', 1);
    dwCourse($foreign, 'foreign', 10);
    $word = Word::query()->where('lesson_id', $lonely->id)->firstOrFail();

    return app(ExercisePromptBuilder::class)->build(ExerciseType::Reverse, $word, new ResolvedTranslation('x', 'ru', false), 'ru', null, 7);
}

it('selects exactly the published public courses of the platform', function (string $status, string $visibility, string $ownerType, bool $listed): void {
    $course = Course::factory()->create(['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);
    $other = Course::factory()->published()->create();

    expect(Course::query()->publicPlatform()->whereKey($course->id)->exists())->toBe($listed)
        ->and(Course::query()->publicPlatform()->whereKey($other->id)->exists())->toBeTrue()
        ->and(Course::query()->publicPlatform()->count())->toBe($listed ? 2 : 1);
})->with([
    'published public platform' => ['published', 'public', 'platform', true],
    'draft' => ['draft', 'public', 'platform', false],
    'review' => ['review', 'public', 'platform', false],
    'archived' => ['archived', 'public', 'platform', false],
    'published but private' => ['published', 'private', 'platform', false],
    'published but unlisted' => ['published', 'unlisted', 'platform', false],
    'published public, an organization course' => ['published', 'public', 'organization', false],
    'published public, a learner deck' => ['published', 'public', 'learner', false],
]);

it('keeps a course outside the whitelist out of the lesson exam and the personal mock', function (string $status, string $visibility, string $ownerType): void {
    $world = dwExamWorld(['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);
    $exams = app(ExamQuestionBuilder::class);

    expect(fn () => $exams->build($world['lesson'], totalQuestions: 4, locale: 'ru'))->toThrow(ExamSessionException::class)
        ->and(fn () => $exams->forWords($world['words'], $world['course'], 'ru'))->toThrow(ExamSessionException::class);
})->with('courses outside the distractor whitelist');

it('keeps a course outside the whitelist out of the reverse exercise, which falls back to flash', function (string $status, string $visibility, string $ownerType): void {
    $prompt = dwReversePrompt(['status' => $status, 'visibility' => $visibility, 'owner_type' => $ownerType]);

    expect($prompt->type)->toBe(ExerciseType::Flash)->and($prompt->options)->toBeNull();
})->with('courses outside the distractor whitelist');

it('borrows from a published public platform course in the lesson exam and the personal mock', function (): void {
    $world = dwExamWorld(['status' => 'published']);
    $exams = app(ExamQuestionBuilder::class);

    foreach ([$exams->build($world['lesson'], totalQuestions: 4, locale: 'ru'), $exams->forWords($world['words'], $world['course'], 'ru')] as $questions) {
        expect($questions)->toHaveCount(4);
        foreach ($questions as $q) {
            $borrowed = array_values(array_diff($q['options'], ['same']));
            expect($borrowed)->toHaveCount(3);
            foreach ($borrowed as $option) {
                expect($option)->toStartWith('foreign-');
            }
        }
    }
});

it('borrows from a published public platform course in the reverse exercise', function (): void {
    $prompt = dwReversePrompt(['status' => 'published']);

    expect($prompt->type)->toBe(ExerciseType::Reverse)
        ->and($prompt->options)->toHaveCount(4)
        ->and($prompt->options)->toContain('lonely0');
    foreach (array_diff((array) $prompt->options, ['lonely0']) as $option) {
        expect($option)->toStartWith('foreign');
    }
});
