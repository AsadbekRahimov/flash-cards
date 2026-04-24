<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamQuestionBuilder;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedLessonForBuilder(int $wordsInTarget = 10, int $distractors = 30): Lesson
{
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count($wordsInTarget)->for($target)->sequence(
        ...array_map(fn (int $i): array => [
            'word' => "w{$i}",
            'translation' => "tr{$i}",
        ], range(0, $wordsInTarget - 1)),
    )->create();

    $sameStageOther = Lesson::factory()->for($stage)->create();
    Word::factory()->count($distractors)->for($sameStageOther)->create();

    return $target;
}

it('builds N questions with 4 distinct options and a deterministic correct_index', function (): void {
    $lesson = seedLessonForBuilder(10, 30);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 5);

    expect($questions)->toHaveCount(5);

    foreach ($questions as $q) {
        expect($q)->toHaveKeys(['word_id', 'word', 'correct_translation', 'correct_index', 'options'])
            ->and($q['options'])->toHaveCount(ExamQuestionBuilder::OPTIONS_PER_QUESTION)
            ->and(array_unique($q['options']))->toHaveCount(ExamQuestionBuilder::OPTIONS_PER_QUESTION)
            ->and($q['options'][$q['correct_index']])->toBe($q['correct_translation']);
    }
});

it('picks each word at most once within a session', function (): void {
    $lesson = seedLessonForBuilder(10, 20);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 10);

    $wordIds = array_column($questions, 'word_id');
    expect(count($wordIds))->toBe(count(array_unique($wordIds)));
});

it('clamps totalQuestions to the words available', function (): void {
    $lesson = seedLessonForBuilder(5, 20);

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 100);

    expect(count($questions))->toBeGreaterThanOrEqual(ExamQuestionBuilder::MIN_QUESTIONS)
        ->and(count($questions))->toBeLessThanOrEqual(5);
});

it('returns empty array when the lesson has fewer than MIN_QUESTIONS words', function (): void {
    $stage = Stage::factory()->create();
    $lesson = Lesson::factory()->for($stage)->create();
    Word::factory()->count(2)->for($lesson)->create();

    $questions = app(ExamQuestionBuilder::class)->build($lesson, totalQuestions: 10);

    expect($questions)->toBeEmpty();
});

it('falls back to filler options when distractor pool is too small', function (): void {
    // only 5 words in target + 2 words total in the stage beyond = tight pool
    $stage = Stage::factory()->create();
    $target = Lesson::factory()->for($stage)->create();
    Word::factory()->count(5)->for($target)->sequence(
        ['translation' => 'aa'],
        ['translation' => 'bb'],
        ['translation' => 'cc'],
        ['translation' => 'dd'],
        ['translation' => 'ee'],
    )->create();

    // only 1 other word in the whole DB → 3 distractors per question is impossible from real data
    $other = Lesson::factory()->for($stage)->create();
    Word::factory()->for($other)->create(['translation' => 'only-one']);

    $questions = app(ExamQuestionBuilder::class)->build($target, totalQuestions: 4);

    expect($questions)->toHaveCount(4);
    foreach ($questions as $q) {
        // must still be exactly 4 options, correct translation present
        expect($q['options'])->toHaveCount(4);
        expect(in_array($q['correct_translation'], $q['options'], true))->toBeTrue();
    }
});
