<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\CollocationGrader;

/*
 * docs/16 §5.4: the answer is an `option_id` of THIS element (422 `unknown_element_id` otherwise); it is right when it is one of the
 * `correct_ids` (one or two of them); `{skip: true}` is `skipped`. A battle round is worth 100 for a right answer, 0 for the rest.
 */

const COLLOCATION_OPTIONS = ['oAAAAAAAAA', 'oBBBBBBBBB', 'oCCCCCCCCC'];

function collocationGrade(array $answer, array $correct = ['oBBBBBBBBB']): GradeResult
{
    return (new CollocationGrader)->grade(COLLOCATION_OPTIONS, $correct, $answer);
}

it('accepts the only right option', function (): void {
    $result = collocationGrade(['option_id' => 'oBBBBBBBBB']);

    expect($result->verdict)->toBe(Verdict::Correct)
        ->and($result->normalizedAnswer)->toBe(['option_id' => 'oBBBBBBBBB'])
        ->and($result->final)->toBeTrue()
        ->and($result->firstAttempt)->toBeTrue()
        ->and($result->points)->toBe(100);
});

it('refuses a wrong option and gives it no points', function (string $optionId): void {
    $result = collocationGrade(['option_id' => $optionId]);

    expect($result->verdict)->toBe(Verdict::Wrong)
        ->and($result->normalizedAnswer)->toBe(['option_id' => $optionId])
        ->and($result->points)->toBe(0);
})->with(['oAAAAAAAAA', 'oCCCCCCCCC']);

it('accepts either of two right options, and only them', function (): void {
    $correct = ['oAAAAAAAAA', 'oCCCCCCCCC'];

    expect(collocationGrade(['option_id' => 'oAAAAAAAAA'], $correct)->verdict)->toBe(Verdict::Correct)
        ->and(collocationGrade(['option_id' => 'oCCCCCCCCC'], $correct)->verdict)->toBe(Verdict::Correct)
        ->and(collocationGrade(['option_id' => 'oCCCCCCCCC'], $correct)->points)->toBe(100)
        ->and(collocationGrade(['option_id' => 'oBBBBBBBBB'], $correct)->verdict)->toBe(Verdict::Wrong);
});

it('takes "I do not know" as a skipped answer: no points, nothing but the skip stored', function (): void {
    $result = collocationGrade(['skip' => true]);

    expect($result->verdict)->toBe(Verdict::Skipped)
        ->and($result->normalizedAnswer)->toBe(['skip' => true])
        ->and($result->points)->toBe(0)
        ->and($result->final)->toBeTrue();
});

it('lets a skip win over an option sent with it, and ignores a skip that is not exactly true', function (): void {
    expect(collocationGrade(['skip' => true, 'option_id' => 'oBBBBBBBBB'])->verdict)->toBe(Verdict::Skipped)
        ->and(collocationGrade(['skip' => false, 'option_id' => 'oBBBBBBBBB'])->verdict)->toBe(Verdict::Correct)
        ->and(fn () => collocationGrade(['skip' => 'yes']))->toThrow(PracticeException::class);
});

it('answers 422 unknown_element_id for an id that is not an option of the element', function (string $optionId): void {
    try {
        collocationGrade(['option_id' => $optionId]);
        $this->fail('The made-up option was accepted.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('unknown_element_id')
            ->and($e->httpStatus())->toBe(422)
            ->and($e->details)->toBe([]);
    }
})->with([
    'a made-up id' => 'oZZZZZZZZZ',
    'an id of the same shape from another element' => 'oDDDDDDDDD',
    'the id of an element' => 'eAAAAAAAAA',
    'the right id in other case' => 'obbbbbbbbb',
    'the right id with a space' => 'oBBBBBBBBB ',
]);

it('answers 422 invalid_answer_shape when there is no option id to read', function (array $answer): void {
    try {
        collocationGrade($answer);
        $this->fail('The malformed answer was accepted.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('invalid_answer_shape')
            ->and($e->httpStatus())->toBe(422)
            ->and($e->details)->toBe(['expected' => 'option_id']);
    }
})->with([
    'an empty answer' => [[]],
    'the id of a fragment' => [['order' => ['fAAAAAAAAA']]],
    'a text' => [['text' => 'make']],
    'an empty id' => [['option_id' => '']],
    'a number' => [['option_id' => 1]],
    'a list' => [['option_id' => ['oBBBBBBBBB']]],
    'null' => [['option_id' => null]],
]);

it('pays a battle round what the config says', function (): void {
    config(['practice.battle.points.round' => 80]);

    expect(collocationGrade(['option_id' => 'oBBBBBBBBB'])->points)->toBe(80)
        ->and(collocationGrade(['option_id' => 'oAAAAAAAAA'])->points)->toBe(0);
});
