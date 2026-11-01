<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Exceptions\PracticeContentException;
use App\Domain\Practice\Support\PracticeContentValidator;

/*
 * docs/16 §4.5: the rules for the TEXT of a task. Here: the order of the fragments is the first accepted order of a sentence
 * (`accepted_first_mismatch`), and a text with `<` or `>` is refused, never cut (`markup_not_allowed`).
 */

function pcv(): PracticeContentValidator
{
    return app(PracticeContentValidator::class);
}

/**
 * A sentence in the form of the author.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pcv_sentence(array $override = []): array
{
    return [
        'prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'],
        'fragments' => ['I', 'usually', 'drink', 'tea'],
        'accepted' => [['I', 'usually', 'drink', 'tea']],
        'explanation' => ['ru' => 'Наречие перед глаголом.', 'uz' => "Ravish fe'ldan oldin."],
        ...$override,
    ];
}

/** The code and the field of the exception the call raises (empty strings when it raises none). @return array{string, string} */
function pcv_fails(Closure $call): array
{
    try {
        $call();
    } catch (PracticeContentException $e) {
        return [$e->errorCode, $e->field];
    }

    return ['', ''];
}

// --- the order of the fragments is the first accepted order -----------------------------------------------------------------

it('keeps the order of the fragments first, and the other accepted orders after it in the order they were given', function (): void {
    $content = pcv()->canonical(PracticeItemType::Sentence, pcv_sentence([
        'accepted' => [['I', 'usually', 'drink', 'tea'], ['usually', 'I', 'drink', 'tea'], ['tea', 'I', 'usually', 'drink']],
    ]));

    expect($content['accepted'])->toBe([[0, 1, 2, 3], [1, 0, 2, 3], [3, 0, 1, 2]]);
});

it('refuses a list that does not start with the order of the fragments: the case of an author who typed only the other order', function (array $accepted): void {
    expect(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['accepted' => $accepted]))))->toBe(['accepted_first_mismatch', 'accepted']);
})->with([
    'the only order is another one' => [[['usually', 'I', 'drink', 'tea']]],
    'the order of the fragments is second' => [[['usually', 'I', 'drink', 'tea'], ['I', 'usually', 'drink', 'tea']]],
    'the order of the fragments is last of three' => [[['usually', 'I', 'drink', 'tea'], ['tea', 'I', 'usually', 'drink'], ['I', 'usually', 'drink', 'tea']]],
    'the same other order twice' => [[['usually', 'I', 'drink', 'tea'], ['usually', 'I', 'drink', 'tea']]],
]);

it('reads the first order the way the grader does: case and punctuation do not tell it from the order of the fragments', function (): void {
    $content = pcv()->canonical(PracticeItemType::Sentence, pcv_sentence([
        'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
        'accepted' => [['My', 'Mother', 'is', 'a', 'teacher.'], ['teacher', 'my', 'mother', 'is', 'a']],
    ]));

    expect($content['accepted'])->toBe([[0, 1, 2, 3, 4], [4, 0, 1, 2, 3]]);
});

it('compares the first order with the fragments when a word stands twice', function (): void {
    $fragments = ['the', 'cat', 'sees', 'the', 'dog'];

    $ok = pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['fragments' => $fragments, 'accepted' => [$fragments, ['the', 'dog', 'sees', 'the', 'cat']]]));

    expect($ok['accepted'])->toBe([[0, 1, 2, 3, 4], [0, 4, 2, 3, 1]])
        ->and(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['fragments' => $fragments, 'accepted' => [['the', 'dog', 'sees', 'the', 'cat']]]))))
        ->toBe(['accepted_first_mismatch', 'accepted']);
});

it('tells a first order that is wrong from the other faults of the list: not a permutation, one order written twice, too many', function (): void {
    $same = ['I', 'usually', 'drink', 'tea'];

    expect(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['accepted' => [['I', 'usually', 'drink']]]))))->toBe(['accepted_not_permutation', 'accepted'])
        ->and(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['accepted' => [$same, $same]]))))->toBe(['accepted_duplicate', 'accepted'])
        ->and(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Sentence, pcv_sentence(['accepted' => []]))))->toBe(['accepted_required', 'accepted']);
});

it('refuses to publish stored content whose first order is not the order of the fragments', function (): void {
    // What the old form could store: the field replaced the order of the fragments (indices; the first order is `usually I drink tea`).
    $stored = [
        'prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men odatda choy ichaman.'],
        'fragments' => ['I', 'usually', 'drink', 'tea'],
        'accepted' => [[1, 0, 2, 3]],
        'explanation' => ['ru' => 'Наречие.', 'uz' => 'Ravish.'],
    ];

    expect(pcv_fails(fn () => pcv()->assertPublishable(PracticeItemType::Sentence, $stored)))->toBe(['accepted_first_mismatch', 'accepted'])
        ->and(pcv_fails(fn () => pcv()->assertPublishable(PracticeItemType::Sentence, [...$stored, 'accepted' => [[0, 1, 2, 3], [1, 0, 2, 3]]])))->toBe(['', '']);
});

// --- a text with < or > is refused ---------------------------------------------------------------------------------------------

it('refuses a text with < or > instead of cutting it off: "I <3 you" is not "I "', function (string $text): void {
    expect(pcv_fails(fn () => pcv()->canonical(PracticeItemType::Dictation, ['text' => $text, 'translation' => ['ru' => 'Я', 'uz' => 'Men']])))->toBe(['markup_not_allowed', 'text']);
})->with([
    'a heart' => ['I <3 you'],
    'a less-than sign' => ['x<y means less'],
    'a greater-than sign' => ['5 > 3'],
    'a tag' => ['Say <b>hello</b>'],
    'a script' => ['<script>alert(1)</script>'],
    'a comment' => ['Hello <!-- x --> there'],
]);

it('says which field has the sign, and in which language', function (): void {
    $try = static function (PracticeItemType $type, array $content): ?PracticeContentException {
        try {
            pcv()->canonical($type, $content);
        } catch (PracticeContentException $e) {
            return $e;
        }

        return null;
    };

    $prompt = $try(PracticeItemType::Sentence, pcv_sentence(['prompt' => ['ru' => 'Я обычно пью чай.', 'uz' => 'Men <odatda> choy ichaman.']]));
    $fragment = $try(PracticeItemType::Sentence, pcv_sentence(['fragments' => ['I', 'usually', 'dr<ink', 'tea'], 'accepted' => [['I', 'usually', 'dr<ink', 'tea']]]));
    $option = $try(PracticeItemType::Collocation, ['text' => "Let's ___ a meeting.", 'options' => ['have', 'ma>ke', 'do'], 'correct' => ['have']]);

    expect([$prompt?->errorCode, $prompt?->field, $prompt?->context['locale'] ?? null])->toBe(['markup_not_allowed', 'prompt', 'uz'])
        ->and([$fragment?->errorCode, $fragment?->field, $fragment?->context['index'] ?? null])->toBe(['markup_not_allowed', 'fragments', 3])
        ->and([$option?->errorCode, $option?->field])->toBe(['markup_not_allowed', 'options']);
});

it('keeps & and quotes, which are text: the pages print them escaped', function (): void {
    $content = pcv()->canonical(PracticeItemType::Dictation, ['text' => 'Tom & Jerry said "hello"', 'translation' => ['ru' => 'Том и Джерри', 'uz' => 'Tom va Jerri']]);

    expect($content['text'])->toBe('Tom & Jerry said "hello"');
});

it('cleans a text of control and invisible characters without the help of strip_tags', function (): void {
    expect(PracticeContentValidator::clean("  See\u{200B}  you\ttomorrow\u{0000}  "))->toBe('See you tomorrow')
        ->and(PracticeContentValidator::clean('a & b'))->toBe('a & b');
});
