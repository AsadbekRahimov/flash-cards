<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Grading\SentenceGrade;
use App\Domain\Practice\Services\Grading\SentenceGrader;

/*
 * docs/16 §5.3: "Build the sentence". The table of the document (8 cases), the other allowed order, the `display`, the forms
 * in which the allowed orders are shown after the answer, and the refusals of an answer that is not an order of all the fragments.
 */

/**
 * The fragments of a task as the snapshot keeps them: ids f1..fN in the order of the texts.
 *
 * @param  list<string>  $texts
 * @return array<string, string>
 */
function sgFragments(array $texts): array
{
    $fragments = [];
    foreach ($texts as $i => $text) {
        $fragments['f'.($i + 1)] = $text;
    }

    return $fragments;
}

/**
 * Grades the answer `$order` (ids) of a task made of `$texts`, allowed in the orders `$sequences` (indices; default: the order of the texts).
 *
 * @param  list<string>  $texts
 * @param  list<string>  $order
 * @param  list<list<int>>|null  $sequences
 */
function sgGrade(array $texts, array $order, ?array $sequences = null, ?string $display = null): SentenceGrade
{
    $grader = new SentenceGrader;
    $sequences ??= [range(0, count($texts) - 1)];

    return $grader->grade(sgFragments($texts), $grader->forms($texts, $sequences, $display)['accepted'], ['order' => $order]);
}

// ---- the table of docs/16 §5.3 ----

it('row 1: the order of the author is correct', function (): void {
    expect(sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f3', 'f4'])->verdict)->toBe(Verdict::Correct);
});

it('row 2: another allowed order ("Usually I drink tea") is correct as well', function (): void {
    $texts = ['I', 'usually', 'drink', 'tea'];
    $allowed = [[0, 1, 2, 3], [1, 0, 2, 3]];

    expect(sgGrade($texts, ['f2', 'f1', 'f3', 'f4'], $allowed)->verdict)->toBe(Verdict::Correct)
        ->and(sgGrade($texts, ['f1', 'f2', 'f3', 'f4'], $allowed)->verdict)->toBe(Verdict::Correct);
});

it('row 3: an order that is not allowed is wrong', function (): void {
    $graded = sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f3', 'f2', 'f4'], [[0, 1, 2, 3], [1, 0, 2, 3]]);

    expect($graded->verdict)->toBe(Verdict::Wrong)
        ->and($graded->order)->toBe(['f1', 'f3', 'f2', 'f4'])
        ->and($graded->text)->toBe('I drink usually tea');
});

it('row 4: the same words are interchangeable: either "the" first, the sentence is the same', function (): void {
    $texts = ['the', 'cat', 'sees', 'the', 'dog'];

    // f1 and f4 are both "the"; the ids are swapped relative to the author's order
    expect(sgGrade($texts, ['f4', 'f2', 'f3', 'f1', 'f5'])->verdict)->toBe(Verdict::Correct)
        ->and(sgGrade($texts, ['f1', 'f2', 'f3', 'f4', 'f5'])->verdict)->toBe(Verdict::Correct)
        ->and(sgGrade($texts, ['f1', 'f4', 'f3', 'f2', 'f5'])->verdict)->toBe(Verdict::Wrong);
});

it('row 5: an answer without all the fragments is refused, not graded: 422 invalid_answer_shape', function (): void {
    try {
        sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f3']);
        $this->fail('The answer with a missing fragment was graded.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('invalid_answer_shape')
            ->and($e->httpStatus())->toBe(422)
            ->and($e->details)->toBe(['expected' => 'order_of_all_fragments']);
    }
});

it('row 6: a fragment twice is refused even when the number of ids is right: 422 invalid_answer_shape', function (): void {
    expect(fn () => sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f3', 'f4', 'f4']))->toThrow(PracticeException::class)
        ->and(fn () => sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f4', 'f4']))->toThrow(PracticeException::class);

    try {
        sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f4', 'f4']);
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('invalid_answer_shape'); // f3 is missing and f4 is there twice: not a permutation
    }
});

it('row 7: a full stop inside a fragment does not matter', function (): void {
    $texts = ['My', 'mother', 'is', 'a', 'teacher.'];

    expect(sgGrade($texts, ['f1', 'f2', 'f3', 'f4', 'f5'])->verdict)->toBe(Verdict::Correct);
    // and a full stop in the text of the allowed order, not in the fragment, does not matter either
    $grader = new SentenceGrader;
    expect($grader->grade(sgFragments($texts), ['My mother is a teacher'], ['order' => ['f1', 'f2', 'f3', 'f4', 'f5']])->verdict)->toBe(Verdict::Correct);
});

it('row 8: "I don\'t know" is skipped, whatever the fragments are', function (): void {
    $grader = new SentenceGrader;
    $graded = $grader->grade(sgFragments(['I', 'usually', 'drink', 'tea']), ['i usually drink tea'], ['skip' => true]);

    expect($graded->verdict)->toBe(Verdict::Skipped)
        ->and($graded->order)->toBe([])
        ->and($graded->text)->toBeNull();
});

// ---- what is not a mistake of the learner ----

it('does not mind case, punctuation, apostrophes or hyphens in the allowed texts', function (): void {
    $grader = new SentenceGrader;
    $fragments = sgFragments(['Don\'t', 'be', 'late']);

    foreach (['dont be late' => false, "DON\u{2019}T BE, LATE!" => true, "don't be-late" => true] as $accepted => $expected) {
        $verdict = $grader->grade($fragments, [$accepted], ['order' => ['f1', 'f2', 'f3']])->verdict;
        expect($verdict)->toBe($expected ? Verdict::Correct : Verdict::Wrong, $accepted);
    }
});

it('compares the text, so the ids of identical words are exchangeable only when the TEXT is the same', function (): void {
    $texts = ['buy', 'one', 'get', 'one', 'free'];

    expect(sgGrade($texts, ['f1', 'f4', 'f3', 'f2', 'f5'])->verdict)->toBe(Verdict::Correct)
        ->and(sgGrade($texts, ['f1', 'f2', 'f4', 'f3', 'f5'])->verdict)->toBe(Verdict::Wrong);
});

// ---- the refusals ----

it('refuses an id that is not a fragment of the task: 422 unknown_element_id', function (): void {
    try {
        sgGrade(['I', 'usually', 'drink', 'tea'], ['f1', 'f2', 'f3', 'fNOPE']);
        $this->fail('An unknown id was graded.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('unknown_element_id')->and($e->httpStatus())->toBe(422);
    }

    // an unknown id is named as such even when the answer is also too short
    expect(fn () => sgGrade(['I', 'usually', 'drink', 'tea'], ['fNOPE']))->toThrow(PracticeException::class, 'not in the issue');
});

it('refuses everything that is not a list of strings: 422 invalid_answer_shape', function (array $answer): void {
    $grader = new SentenceGrader;

    try {
        $grader->grade(sgFragments(['I', 'drink', 'tea']), ['i drink tea'], $answer);
        $this->fail('A malformed answer was graded.');
    } catch (PracticeException $e) {
        expect($e->errorCode)->toBe('invalid_answer_shape')->and($e->details)->toBe(['expected' => 'order_of_all_fragments']);
    }
})->with([
    'nothing' => [[]],
    'skip: false is no skip' => [['skip' => false]],
    'skip as a number' => [['skip' => 1]],
    'order is a string' => [['order' => 'f1 f2 f3']],
    'order is empty' => [['order' => []]],
    'order is a map' => [['order' => ['a' => 'f1', 'b' => 'f2', 'c' => 'f3']]],
    'order holds a number' => [['order' => ['f1', 2, 'f3']]],
    'order holds a list' => [['order' => ['f1', ['f2'], 'f3']]],
    'order has too many ids' => [['order' => ['f1', 'f2', 'f3', 'f1']]],
]);

it('lets "I don\'t know" win over an order sent with it', function (): void {
    $graded = (new SentenceGrader)->grade(sgFragments(['I', 'drink', 'tea']), ['i drink tea'], ['skip' => true, 'order' => ['f1', 'f2', 'f3']]);

    expect($graded->verdict)->toBe(Verdict::Skipped)->and($graded->order)->toBe([]);
});

// ---- the forms of the allowed orders ----

it('writes the allowed orders out with a capital letter and a full stop, and keeps the normalized text for the comparison', function (): void {
    $forms = (new SentenceGrader)->forms(['I', 'usually', 'drink', 'tea'], [[0, 1, 2, 3], [1, 0, 2, 3]]);

    expect($forms['accepted'])->toBe(['i usually drink tea', 'usually i drink tea'])
        ->and($forms['accepted_display'])->toBe(['I usually drink tea.', 'Usually I drink tea.']);
});

it('shows the `display` of the author as the first form and writes the others out by the rule', function (): void {
    $forms = (new SentenceGrader)->forms(['I', 'usually', 'drink', 'tea'], [[0, 1, 2, 3], [1, 0, 2, 3]], '  I usually drink tea.  ');

    expect($forms['accepted_display'])->toBe(['I usually drink tea.', 'Usually I drink tea.'])
        ->and($forms['accepted'])->toBe(['i usually drink tea', 'usually i drink tea']);

    // the display is what the author wrote, down to the punctuation the rule would not add
    $forms = (new SentenceGrader)->forms(['Where', 'do', 'you', 'live'], [[0, 1, 2, 3]], 'Where do you live?');
    expect($forms['accepted_display'])->toBe(['Where do you live?']);
});

it('puts no second full stop where the text has its mark already, a closing quote or bracket included', function (): void {
    $grader = new SentenceGrader;

    expect($grader->forms(['My', 'mother', 'is', 'a', 'teacher.'], [[0, 1, 2, 3, 4]])['accepted_display'])->toBe(['My mother is a teacher.'])
        ->and($grader->forms(['He', 'said', '"Go!"'], [[0, 1, 2]])['accepted_display'])->toBe(['He said "Go!"'])
        ->and($grader->forms(['Is', 'it', 'yours?'], [[0, 1, 2]])['accepted_display'])->toBe(['Is it yours?'])
        ->and($grader->forms(['Well', "maybe\u{2026}"], [[0, 1]])['accepted_display'])->toBe(["Well maybe\u{2026}"]);
});

it('ends the other forms with the question or exclamation mark of the display', function (): void {
    $forms = (new SentenceGrader)->forms(['Where', 'do', 'you', 'live', 'now'], [[0, 1, 2, 3, 4], [0, 1, 2, 4, 3]], 'Where do you live now?');

    expect($forms['accepted_display'])->toBe(['Where do you live now?', 'Where do you now live?']);
});

it('keeps an order once, however often it is allowed, and the first one stays the first', function (): void {
    $forms = (new SentenceGrader)->forms(['the', 'cat', 'the'], [[0, 1, 2], [2, 1, 0], [0, 1, 2]]);

    expect($forms['accepted'])->toBe(['the cat the'])
        ->and($forms['accepted_display'])->toBe(['The cat the.']);

    $forms = (new SentenceGrader)->forms(['a', 'b'], [[1, 0], [0, 1], [1, 0]]);
    expect($forms['accepted'])->toBe(['b a', 'a b'])->and($forms['accepted_display'])->toBe(['B a.', 'A b.']);
});

it('leaves out an order that points nowhere or says nothing', function (): void {
    $forms = (new SentenceGrader)->forms(['!', '?'], [[0, 1], [5, 6]]);

    expect($forms)->toBe(['accepted' => [], 'accepted_display' => []]);
});

it('capitalizes the first letter only, in any script', function (): void {
    $grader = new SentenceGrader;

    expect($grader->capitalized('mother my is teacher a'))->toBe('Mother my is teacher a')
        ->and($grader->capitalized("\u{00E9}lan vital"))->toBe("\u{00C9}lan vital")
        ->and($grader->capitalized('Already'))->toBe('Already')
        ->and($grader->capitalized('2 apples'))->toBe('2 apples')
        ->and($grader->capitalized(''))->toBe('');
});

it('joins the fragments of an order with a space and leaves out an id that is no fragment', function (): void {
    $grader = new SentenceGrader;
    $fragments = sgFragments(['My', 'mother', 'is']);

    expect($grader->join($fragments, ['f2', 'f1', 'f3']))->toBe('mother My is')
        ->and($grader->join($fragments, ['f2', 'fX']))->toBe('mother')
        ->and($grader->join($fragments, []))->toBe('');
});
