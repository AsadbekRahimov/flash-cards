<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\WordFormGrader;

/*
 * docs/16 §5.6: the form of a word the learner typed - `correct` when its tokens are those of one of the right forms (case, punctuation,
 * the hyphen and the British / American spelling do not matter), `wrong` otherwise (no typo: the building of the form is the skill),
 * `skipped` for "I don't know" and an empty text; a review flag when the base word itself was typed.
 */

/** @param list<string> $accepted */
function wordFormGrade(array $answer, array $accepted = ['decision'], string $base = 'decide'): GradeResult
{
    return (new WordFormGrader)->grade($accepted, $base, $answer);
}

it('accepts the right form however it is written: case, a full stop, spaces', function (string $text): void {
    $result = wordFormGrade(['text' => $text]);

    expect($result->verdict)->toBe(Verdict::Correct)
        ->and($result->final)->toBeTrue()
        ->and($result->points)->toBe(100)
        ->and($result->normalizedAnswer)->toBe(['text' => trim($text)])
        ->and($result->reveal)->toBe(['your_text' => trim($text), 'same_as_base' => false]);
})->with(['decision', 'Decision.', '  decision ', 'DECISION!']);

it('refuses another form and says when the base word itself was typed', function (string $text, bool $sameAsBase): void {
    $result = wordFormGrade(['text' => $text]);

    expect($result->verdict)->toBe(Verdict::Wrong)
        ->and($result->points)->toBe(0)
        ->and($result->reveal['same_as_base'])->toBe($sameAsBase);
})->with([
    'the plural' => ['decisions', false],
    'the base word' => ['decide', true],
    'the base word in capitals' => ['Decide.', true],
    'another word' => ['choice', false],
]);

it('takes the British and the American spelling of a form both ways', function (array $accepted, string $text): void {
    expect(wordFormGrade(['text' => $text], $accepted, 'colourful')->verdict)->toBe(Verdict::Correct);
})->with([
    'colour for color' => [['color'], 'colour'],
    'color for colour' => [['colour'], 'color'],
]);

it('accepts every listed form, of one or more words, a hyphen standing for a space', function (array $accepted, string $text): void {
    expect(wordFormGrade(['text' => $text], $accepted, 'beautiful')->verdict)->toBe(Verdict::Correct);
})->with([
    'the second of two' => [['practice', 'practise'], 'practise'],
    'two words, two spaces' => [['more beautiful'], 'more  beautiful'],
    'two words, a hyphen' => [['more beautiful'], 'more-beautiful'],
]);

it('takes a badly built form for a mistake, not a typo', function (): void {
    expect(wordFormGrade(['text' => 'happyer'], ['happier'], 'happy')->verdict)->toBe(Verdict::Wrong)
        ->and(wordFormGrade(['text' => 'desicion'])->verdict)->toBe(Verdict::Wrong);
});

it('takes "I don\'t know" and an empty text for a skip', function (array $answer): void {
    $result = wordFormGrade($answer);

    expect($result->verdict)->toBe(Verdict::Skipped)
        ->and($result->normalizedAnswer)->toBe(['skip' => true])
        ->and($result->reveal)->toBe(['your_text' => null, 'same_as_base' => false]);
})->with([
    'skip' => [['skip' => true]],
    'empty' => [['text' => '']],
    'punctuation only' => [['text' => ' ...! ']],
]);

it('refuses an answer of another shape: 422 text_or_skip', function (array $answer): void {
    expect(fn () => wordFormGrade($answer))->toThrow(function (PracticeException $e): void {
        expect($e->errorCode)->toBe('invalid_answer_shape')
            ->and($e->details)->toBe(['expected' => 'text_or_skip'])
            ->and($e->httpStatus())->toBe(422);
    });
})->with([
    'an order' => [['order' => ['fA', 'fB']]],
    'an option' => [['option_id' => 'oA']],
    'a number' => [['text' => 42]],
    'skip false' => [['skip' => false]],
]);

it('grades only the beginning of a very long answer and keeps 200 characters of it', function (): void {
    $long = 'decision '.str_repeat('x', 400);
    $result = wordFormGrade(['text' => $long]);

    expect($result->verdict)->toBe(Verdict::Wrong)
        ->and(mb_strlen((string) $result->normalizedAnswer['text']))->toBe(200)
        ->and(wordFormGrade(['text' => str_repeat('decision ', 30)])->verdict)->toBe(Verdict::Wrong);
});
