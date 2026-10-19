<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamAnswerService;
use App\Domain\Learning\Services\ExamQuestionPersonalizer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to a valid canonical question list, not a number', function (): void {
    $session = ExamSession::factory()->create();

    expect($session->config['questions'])->toBeArray()->toHaveCount(4)
        ->and($session->config['total_questions'])->toBe(4)
        ->and(app(ExamQuestionPersonalizer::class)->count($session))->toBe(4);

    foreach ($session->config['questions'] as $question) {
        expect($question)->toHaveKeys(['word_id', 'word', 'correct_translation', 'options'])
            ->and($question['options'])->toContain($question['correct_translation'])
            ->and($question)->not->toHaveKey('correct_index');
    }
});

it('withQuestions(n) stores n canonical questions backed by real words of the lesson', function (): void {
    $session = ExamSession::factory()->withQuestions(6)->create();

    expect($session->config['questions'])->toHaveCount(6)
        ->and($session->config['total_questions'])->toBe(6)
        ->and($session->config['question_order'])->toBe(ExamQuestionPersonalizer::ORDER_PER_LEARNER);

    $wordIds = array_column($session->config['questions'], 'word_id');
    expect(Word::query()->where('lesson_id', $session->lesson_id)->whereIn('id', $wordIds)->count())->toBe(6)
        ->and(array_unique($wordIds))->toHaveCount(6);
});

it('withQuestions(n) yields an exam that can actually be played', function (): void {
    $session = ExamSession::factory()->withQuestions(4)->create();
    $learner = Learner::factory()->create();
    $personalizer = app(ExamQuestionPersonalizer::class);
    $answers = app(ExamAnswerService::class);

    $view = $personalizer->questionAt($session, $learner, 0);
    $answers->serve($session, $learner, 0);
    $result = $answers->submit($session, $learner, 0, $view['word_id'], $view['correct_index']);

    expect($result['is_correct'])->toBeTrue();
});
