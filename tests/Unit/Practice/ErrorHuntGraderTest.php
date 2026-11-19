<?php

declare(strict_types=1);

use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\ErrorHuntGrader;

/*
 * docs/16 §5.7: the two steps of "find the mistake". `locate` takes a `token_id` of the element, `fix` an `option_id`; an id that is
 * not the element's is 422 `unknown_element_id`, the answer of the other step is 409 `step_not_current` with the step the server
 * expects, nothing at all is 422 `invalid_answer_shape`.
 */

const ERROR_HUNT_TOKENS = ['tAAAAAAAAA', 'tBBBBBBBBB', 'tCCCCCCCCC', 'tDDDDDDDDD'];
const ERROR_HUNT_OPTIONS = ['oAAAAAAAAA', 'oBBBBBBBBB', 'oCCCCCCCCC'];

function errorHuntLocate(array $answer): bool
{
    return (new ErrorHuntGrader)->locate(ERROR_HUNT_TOKENS, 'tCCCCCCCCC', $answer);
}

function errorHuntFix(array $answer): bool
{
    return (new ErrorHuntGrader)->fix(ERROR_HUNT_OPTIONS, ['oBBBBBBBBB'], $answer);
}

/** @return array{string, int, array<string, mixed>} the code, the status and the details of the refusal */
function errorHuntRefusal(Closure $call): array
{
    try {
        $call();
    } catch (PracticeException $e) {
        return [$e->errorCode, $e->httpStatus(), $e->details];
    }

    return ['', 0, []];
}

it('finds the wrong word at the step locate, and only it', function (): void {
    expect(errorHuntLocate(['token_id' => 'tCCCCCCCCC']))->toBeTrue()
        ->and(errorHuntLocate(['token_id' => 'tAAAAAAAAA']))->toBeFalse()
        ->and(errorHuntLocate(['token_id' => 'tDDDDDDDDD']))->toBeFalse();
});

it('takes the right correction at the step fix, and only it', function (): void {
    expect(errorHuntFix(['option_id' => 'oBBBBBBBBB']))->toBeTrue()
        ->and(errorHuntFix(['option_id' => 'oAAAAAAAAA']))->toBeFalse()
        ->and(errorHuntFix(['option_id' => 'oCCCCCCCCC']))->toBeFalse();
});

it('refuses an id that is not one of the element\'s: 422 unknown_element_id', function (string $step, array $answer): void {
    $call = $step === 'locate' ? fn () => errorHuntLocate($answer) : fn () => errorHuntFix($answer);

    expect(errorHuntRefusal($call))->toBe(['unknown_element_id', 422, []]);
})->with([
    'a made-up word' => ['locate', ['token_id' => 'tZZZZZZZZZ']],
    'an option as a word' => ['locate', ['token_id' => 'oBBBBBBBBB']],
    'a made-up option' => ['fix', ['option_id' => 'oZZZZZZZZZ']],
    'a word as an option' => ['fix', ['option_id' => 'tCCCCCCCCC']],
]);

it('refuses the answer of the other step: 409 step_not_current with the step the server expects', function (): void {
    expect(errorHuntRefusal(fn () => errorHuntLocate(['option_id' => 'oBBBBBBBBB'])))->toBe(['step_not_current', 409, ['step' => 'locate']])
        ->and(errorHuntRefusal(fn () => errorHuntFix(['token_id' => 'tCCCCCCCCC'])))->toBe(['step_not_current', 409, ['step' => 'fix']]);
});

it('refuses an answer without the key of the step: 422 invalid_answer_shape', function (): void {
    expect(errorHuntRefusal(fn () => errorHuntLocate([])))->toBe(['invalid_answer_shape', 422, ['expected' => 'token_id']])
        ->and(errorHuntRefusal(fn () => errorHuntLocate(['token_id' => 7])))->toBe(['invalid_answer_shape', 422, ['expected' => 'token_id']])
        ->and(errorHuntRefusal(fn () => errorHuntLocate(['token_id' => ''])))->toBe(['invalid_answer_shape', 422, ['expected' => 'token_id']])
        ->and(errorHuntRefusal(fn () => errorHuntFix(['text' => 'in'])))->toBe(['invalid_answer_shape', 422, ['expected' => 'option_id']]);
});
