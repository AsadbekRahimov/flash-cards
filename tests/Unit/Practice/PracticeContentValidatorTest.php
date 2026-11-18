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

// --- "word form" and "find the mistake" (docs/16 §5.6, §5.7; §4.5 of the codes) ----------------------------------------------

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pcv_word_form(array $override = []): array
{
    return [
        'text' => 'We need to make a ___ by Friday.',
        'base' => 'decide',
        'accepted' => ['decision'],
        'translation' => ['ru' => 'Нам нужно принять решение до пятницы.', 'uz' => 'Juma kunigacha qaror qabul qilishimiz kerak.'],
        'explanation' => ['ru' => 'После make a нужно существительное.', 'uz' => 'make a dan keyin ot kerak.'],
        ...$override,
    ];
}

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pcv_error_hunt(array $override = []): array
{
    return [
        'text' => 'I am very interested *on* modern art.',
        'options' => ['in', 'at', 'for'],
        'correct' => ['in'],
        'translation' => ['ru' => 'Меня очень интересует современное искусство.', 'uz' => "Men zamonaviy san'atga juda qiziqaman."],
        'explanation' => ['ru' => 'interested in — интересоваться чем-то.', 'uz' => 'interested in — biror narsaga qiziqmoq.'],
        ...$override,
    ];
}

it('stores a word form as written: the forms cleaned, those that read the same kept once, the first first', function (): void {
    $content = pcv()->canonical(PracticeItemType::WordForm, pcv_word_form([
        'accepted' => ['  practice ', 'practise', 'Practice', "well-\u{200B}known"],
    ]));

    expect(array_keys($content))->toBe(['text', 'base', 'accepted', 'translation', 'explanation'])
        ->and($content['accepted'])->toBe(['practice', 'practise', 'well-known'])
        ->and($content['base'])->toBe('decide');
});

it('refuses each fault of a word form with its code and field', function (array $override, string $code, string $field): void {
    expect(pcv_fails(fn () => pcv()->canonical(PracticeItemType::WordForm, pcv_word_form($override))))->toBe([$code, $field]);
})->with([
    'no text' => [['text' => ' '], 'text_required', 'text'],
    'text too long' => [['text' => str_repeat('a', 158).' ___'], 'text_too_long', 'text'],
    'no gap' => [['text' => 'We need to make a decision.'], 'placeholder_invalid', 'text'],
    'two gaps' => [['text' => 'We ___ to make a ___.'], 'placeholder_invalid', 'text'],
    'a long gap' => [['text' => 'We need to make a ____.'], 'placeholder_invalid', 'text'],
    'no base' => [['base' => ''], 'base_invalid', 'base'],
    'base of three words' => [['base' => 'look up to'], 'base_invalid', 'base'],
    'base with a digit' => [['base' => 'go2'], 'base_invalid', 'base'],
    'base too long' => [['base' => str_repeat('a', 31)], 'base_invalid', 'base'],
    'base with markup' => [['base' => '<b>'], 'markup_not_allowed', 'base'],
    'no accepted' => [['accepted' => []], 'accepted_required', 'accepted'],
    'accepted not a list' => [['accepted' => 'decision'], 'accepted_required', 'accepted'],
    'too many accepted' => [['accepted' => ['a', 'b', 'c', 'd', 'e']], 'accepted_too_many', 'accepted'],
    'answer equals the base' => [['accepted' => ['decision', 'Decide']], 'answer_equals_base', 'accepted'],
]);

it('tells the cause of a bad right form', function (mixed $answer, string $cause): void {
    try {
        pcv()->canonical(PracticeItemType::WordForm, pcv_word_form(['accepted' => ['decision', $answer]]));
        $this->fail('no exception');
    } catch (PracticeContentException $e) {
        expect($e->errorCode)->toBe('answer_invalid')
            ->and($e->field)->toBe('accepted')
            ->and($e->context['cause'])->toBe($cause)
            ->and($e->context['index'])->toBe(2);
    }
})->with([
    'empty' => ['  ', 'empty'],
    'only punctuation' => ['...', 'empty'],
    'not a text' => [42, 'empty'],
    'too long' => [str_repeat('a', 41), 'length'],
    'four words' => ['the most very beautiful', 'tokens'],
    'a digit' => ['2nd', 'chars'],
    'a full stop' => ['decision.', 'chars'],
]);

it('takes up to four right forms of up to three words', function (): void {
    $content = pcv()->canonical(PracticeItemType::WordForm, pcv_word_form([
        'base' => 'good', 'text' => 'This is ___ day of my life.', 'accepted' => ['the best', 'the very best', "the year's best", 'best'],
    ]));

    expect($content['accepted'])->toHaveCount(4);
});

it('stores a find-the-mistake without the asterisks, with the number and the core of the wrong word', function (): void {
    $content = pcv()->canonical(PracticeItemType::ErrorHunt, pcv_error_hunt());

    expect(array_keys($content))->toBe(['text', 'error_index', 'error', 'options', 'correct', 'translation', 'explanation'])
        ->and($content['text'])->toBe('I am very interested on modern art.')
        ->and($content['error_index'])->toBe(4)
        ->and($content['error'])->toBe('on')
        ->and($content['correct'])->toBe([0]);
});

it('takes a mark next to punctuation, inside a word with an apostrophe and on a word that stands twice', function (string $text, int $index, string $error, string $stored): void {
    $content = pcv()->canonical(PracticeItemType::ErrorHunt, pcv_error_hunt(['text' => $text, 'options' => ['information', 'informing', 'informed'], 'correct' => ['information']]));

    expect($content['error_index'])->toBe($index)
        ->and($content['error'])->toBe($error)
        ->and($content['text'])->toBe($stored);
})->with([
    'a full stop after it' => ['She gave me some useful *informations*.', 5, 'informations', 'She gave me some useful informations.'],
    'an apostrophe inside' => ["The dog wagged *it's* tail happily.", 3, "it's", "The dog wagged it's tail happily."],
    'the second of two' => ['I saw the cat on *the* the sofa.', 5, 'the', 'I saw the cat on the the sofa.'],
    'the first word' => ['*Their* is a cat in the garden.', 0, 'Their', 'Their is a cat in the garden.'],
]);

it('refuses each fault of a find-the-mistake with its code and field', function (array $override, string $code, string $field): void {
    expect(pcv_fails(fn () => pcv()->canonical(PracticeItemType::ErrorHunt, pcv_error_hunt($override))))->toBe([$code, $field]);
})->with([
    'no text' => [['text' => ''], 'text_required', 'text'],
    'only asterisks' => [['text' => '**'], 'text_required', 'text'],
    'no mark' => [['text' => 'I am very interested on modern art.'], 'error_mark_invalid', 'text'],
    'two marks' => [['text' => 'I *am* very interested *on* modern art.'], 'error_mark_invalid', 'text'],
    'a lone asterisk' => [['text' => 'I am very interested *on modern art.'], 'error_mark_invalid', 'text'],
    'two words marked' => [['text' => 'I am very interested *on modern* art.'], 'error_mark_invalid', 'text'],
    'punctuation inside' => [['text' => 'I am very interested on modern *art.*'], 'error_mark_invalid', 'text'],
    'part of a word' => [['text' => 'I am very interested*on* modern art.'], 'error_mark_invalid', 'text'],
    'an empty mark' => [['text' => 'I am very ** interested modern art.'], 'error_mark_invalid', 'text'],
    'too long' => [['text' => '*on* '.str_repeat('word ', 31).'end'], 'text_too_long', 'text'],
    'too few words' => [['text' => 'Interested *on* art.'], 'tokens_count', 'text'],
    'too many words' => [['text' => '*on* '.trim(str_repeat('a ', 16))], 'tokens_count', 'text'],
    'a word of punctuation' => [['text' => 'I am very — interested *on* modern art.'], 'token_invalid', 'text'],
    'a word too long' => [['text' => 'I am very '.str_repeat('a', 41).' *on* art.'], 'token_invalid', 'text'],
    'two options' => [['options' => ['in', 'at']], 'options_count', 'options'],
    'four options' => [['options' => ['in', 'at', 'for', 'by']], 'options_count', 'options'],
    'options the same' => [['options' => ['in', 'In', 'for']], 'options_duplicate', 'options'],
    'an option too long' => [['options' => ['in', str_repeat('a', 41), 'for']], 'text_too_long', 'options'],
    'an option of four words' => [['options' => ['in', 'at the very top', 'for']], 'text_too_long', 'options'],
    'the error among the options' => [['options' => ['in', 'On', 'for']], 'option_is_error', 'options'],
    'no correct' => [['correct' => []], 'correct_invalid', 'correct'],
    'two correct' => [['correct' => ['in', 'at']], 'correct_invalid', 'correct'],
    'a correct that is no option' => [['correct' => ['by']], 'correct_invalid', 'correct'],
    'correct as a text' => [['correct' => 'in'], 'correct_invalid', 'correct'],
]);

it('goes the round canonical -> author form -> canonical for both new types', function (PracticeItemType $type, array $author): void {
    $stored = pcv()->canonical($type, $author);
    $back = pcv()->authorForm($type, $stored);

    expect(pcv()->canonical($type, $back))->toBe($stored);
})->with([
    'word form' => [PracticeItemType::WordForm, pcv_word_form(['accepted' => ['practice', 'practise']])],
    'find the mistake' => [PracticeItemType::ErrorHunt, pcv_error_hunt()],
    'a mark before a full stop' => [PracticeItemType::ErrorHunt, pcv_error_hunt(['text' => 'She gave me some useful *informations*.', 'options' => ['information', 'informing', 'informed'], 'correct' => ['information']])],
    'a repeated word' => [PracticeItemType::ErrorHunt, pcv_error_hunt(['text' => 'I saw the cat on *the* the sofa.', 'options' => ['a', 'an', 'this'], 'correct' => ['a']])],
]);

it('gives the author form of a find-the-mistake with the asterisks back and correct as a text', function (): void {
    $author = pcv()->authorForm(PracticeItemType::ErrorHunt, pcv()->canonical(PracticeItemType::ErrorHunt, pcv_error_hunt([
        'text' => 'She gave me some useful *informations*.', 'options' => ['informed', 'information', 'informing'], 'correct' => ['information'],
    ])));

    expect($author['text'])->toBe('She gave me some useful *informations*.')
        ->and($author['correct'])->toBe(['information'])
        ->and($author)->not->toHaveKey('error_index')->not->toHaveKey('error');
});

it('wants the translation and the explanation in every language to publish a task of a new type', function (PracticeItemType $type, array $author, string $field): void {
    $author[$field] = ['ru' => 'Только по-русски.'];
    $stored = pcv()->canonical($type, $author);

    expect(pcv_fails(fn () => pcv()->assertPublishable($type, $stored)))->toBe(['locale_text_required', $field])
        ->and(pcv_fails(fn () => pcv()->assertPublishable($type, pcv()->canonical($type, $type === PracticeItemType::WordForm ? pcv_word_form() : pcv_error_hunt()))))->toBe(['', '']);
})->with([
    'word form, translation' => [PracticeItemType::WordForm, pcv_word_form(), 'translation'],
    'word form, explanation' => [PracticeItemType::WordForm, pcv_word_form(), 'explanation'],
    'find the mistake, translation' => [PracticeItemType::ErrorHunt, pcv_error_hunt(), 'translation'],
    'find the mistake, explanation' => [PracticeItemType::ErrorHunt, pcv_error_hunt(), 'explanation'],
]);
