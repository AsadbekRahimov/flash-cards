<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Support\InflectionGuard;

/*
 * docs/16 §5.2: the verdicts of a dictation - the table of 21 cases of the document, the rules of a safe typo one by one, the
 * best verdict over the accepted variants, the review (`expected`, `your_text`, `diff`) - and InflectionGuard (§14.1).
 */

function dictationGrader(): DictationGrader
{
    return app(DictationGrader::class);
}

/** @param list<string> $expected */
function dictationVerdict(array $expected, string $answer): Verdict
{
    return dictationGrader()->grade($expected, $answer)->verdict;
}

// ---------------------------------------------------------------- the table of docs/16 §5.2

it('gives the verdict of the table of docs/16 §5.2', function (array $expected, string $answer, string $verdict): void {
    expect(dictationVerdict($expected, $answer)->value)->toBe($verdict);
})->with([
    '1  case and punctuation' => [['See you tomorrow'], 'see you tomorrow!', 'correct'],
    '2  extra spaces' => [['See you tomorrow'], 'See  you   tomorrow', 'correct'],
    '3  one token, OSA 1, 8 letters, not a form' => [['See you tomorrow'], 'see you tomorow', 'typo'],
    '4  a word: beautiful' => [['beautiful'], 'beatiful', 'typo'],
    '5  a transposition is one edit' => [['receive'], 'recieve', 'typo'],
    '6  numerals are protected (and far apart)' => [['I have fifteen apples'], 'I have fifty apples', 'wrong'],
    '7  a missing "not" is another number of tokens' => [['She does not like tea'], 'She does like tea', 'wrong'],
    '8  n\'t is protected' => [["I don't know"], 'I dont know', 'wrong'],
    '9  a form: likes / liked (rule b)' => [['He likes coffee'], 'He liked coffee', 'wrong'],
    '10 a form: teacher / teachers (rule a)' => [['teacher'], 'teachers', 'wrong'],
    '11 a short word is not forgiven' => [['I want tea'], 'I wont tea', 'wrong'],
    '12 there / their are two edits apart' => [['There is a cat'], 'Their is a cat', 'wrong'],
    '13 a variant of the author' => [['I am a student', "I'm a student"], "i'm a student.", 'correct'],
    '14 a contraction is not the long form' => [['I am a student'], "I'm a student", 'wrong'],
    '15 nothing written' => [['See you tomorrow'], '', 'skipped'],
    '16 a hyphen is a space' => [['Ice cream'], 'ice-cream', 'correct'],
    '17 two tokens differ' => [["It's five o'clock"], 'its five oclock', 'wrong'],
    '18 walkd: not a form of walked' => [['We walked home'], 'We walkd home', 'typo'],
    '19 a form: coffee / coffees (a stemmer would call it a typo)' => [['I like coffee'], 'I like coffees', 'wrong'],
    '20 the lost final d is a mistake on purpose' => [['My friend'], 'My frien', 'wrong'],
    '21 a form: watched / watches (rule b)' => [['They watched TV'], 'They watches TV', 'wrong'],
]);

it('is skipped for a written nothing: spaces, punctuation only', function (string $answer): void {
    $result = dictationGrader()->grade(['See you tomorrow'], $answer);

    expect($result->verdict)->toBe(Verdict::Skipped)
        ->and($result->normalizedAnswer)->toBe(['skip' => true])
        ->and($result->points)->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   '], 'a new line' => ["\n"], 'punctuation' => ['?!. ,'], 'dashes' => [' - — ']]);

// ---------------------------------------------------------------- the rules of a safe typo, one by one

it('forgives a misspelt word that is long enough, and only a word that is long enough', function (): void {
    expect(dictationVerdict(['I need water'], 'I need watre'))->toBe(Verdict::Typo)          // 5 letters, a transposition
        ->and(dictationVerdict(['Open the door'], 'Open the dor'))->toBe(Verdict::Wrong)     // 4 letters
        ->and(dictationVerdict(['Open the door'], 'Open the doorr'))->toBe(Verdict::Wrong);  // 4 letters, one more
});

it('takes the shortest forgiven word from the config', function (): void {
    config(['practice.grading.typo_min_length' => 4]);
    expect(dictationVerdict(['Open the door'], 'Open the dor'))->toBe(Verdict::Typo);

    config(['practice.grading.typo_min_length' => 8]);
    expect(dictationVerdict(['See you tomorrow'], 'see you tomorow'))->toBe(Verdict::Typo)  // tomorrow: 8
        ->and(dictationVerdict(['I need water'], 'I need watre'))->toBe(Verdict::Wrong);
});

it('forgives one token and not two: a second slip is a mistake', function (): void {
    expect(dictationVerdict(['Where is the station'], 'Where is the staton'))->toBe(Verdict::Typo)
        ->and(dictationVerdict(['Where is the station'], 'Wher is the staton'))->toBe(Verdict::Wrong)
        ->and(dictationVerdict(['Where is the station'], 'Where is the stattion today'))->toBe(Verdict::Wrong);
});

it('does not forgive a word that is a different word: more than one edit', function (): void {
    expect(dictationVerdict(['a beautiful day'], 'a beautifully day'))->toBe(Verdict::Wrong)  // two edits
        ->and(dictationVerdict(['a beautiful day'], 'a beatifull day'))->toBe(Verdict::Wrong);  // two edits
});

it('keeps the numerals, the negations and the words with digits out of the forgiven ones', function (): void {
    // "eleven" / "elevan": one edit, six letters - and protected; the word next to it is forgiven as any other.
    expect(dictationVerdict(['I have eleven books'], 'I have elevan books'))->toBe(Verdict::Wrong)
        ->and(dictationVerdict(['I have eleven books'], 'I have eleven bokos'))->toBe(Verdict::Typo)
        ->and(dictationVerdict(['nothing happens'], 'nothin happens'))->toBe(Verdict::Wrong)           // "nothing" is protected
        ->and(dictationVerdict(['never again'], 'nevar again'))->toBe(Verdict::Wrong)                    // "never" is protected
        ->and(dictationVerdict(['room 12345 is open'], 'room 12354 is open'))->toBe(Verdict::Wrong)     // digits: a long enough number
        ->and(dictationVerdict(['the 100th year'], 'the 100st year'))->toBe(Verdict::Wrong);            // a digit inside the token
});

it('reads the list of protected tokens from the config', function (): void {
    expect(dictationVerdict(['A yellow flower'], 'A yelow flower'))->toBe(Verdict::Typo);

    config(['practice.grading.protected_tokens' => ['yellow']]);
    expect(dictationVerdict(['A yellow flower'], 'A yelow flower'))->toBe(Verdict::Wrong);
});

it('does not forgive the wrong writing of a negative contraction, in either direction', function (): void {
    expect(dictationVerdict(["She doesn't know"], 'She doesnt know'))->toBe(Verdict::Wrong)   // "doesn't" is expected
        ->and(dictationVerdict(['She doesnt know'], "She doesn't know"))->toBe(Verdict::Wrong) // "doesn't" is typed
        ->and(dictationVerdict(["I can't swim"], "I can't swimm"))->toBe(Verdict::Wrong);      // swim: 4 letters
});

it('treats the typographic apostrophe and the straight one alike', function (): void {
    expect(dictationVerdict(["It's five o'clock"], "It\u{2019}s five o\u{2019}clock"))->toBe(Verdict::Correct)
        ->and(dictationVerdict(["I don't know"], "I don\u{2019}t know"))->toBe(Verdict::Correct);
});

// ---------------------------------------------------------------- the variants

it('takes the best verdict over all the accepted variants', function (): void {
    $variants = ['I am a student', "I'm a student", 'I am a learner'];

    expect(dictationVerdict($variants, "I'm a student"))->toBe(Verdict::Correct)           // variant 2 fits exactly
        ->and(dictationVerdict($variants, 'I am a learnr'))->toBe(Verdict::Typo)             // a typo against variant 3
        ->and(dictationVerdict($variants, 'I am a studnet'))->toBe(Verdict::Typo)             // a typo against variant 1
        ->and(dictationVerdict($variants, 'I am a teacher'))->toBe(Verdict::Wrong);
});

it('prefers a correct variant to a typo of another', function (): void {
    // "color" against variant 1 ("colour": six letters, one edit) is a typo; against variant 2 it is exact.
    expect(dictationVerdict(['Nice colour', 'Nice color'], 'nice color'))->toBe(Verdict::Correct)
        ->and(dictationVerdict(['Nice colour', 'Nice color'], 'nice colr'))->toBe(Verdict::Typo); // one edit from "color", two from "colour"
});

it('accepts a variant that the author wrote with other capitals and punctuation', function (): void {
    expect(dictationVerdict(['Hello, World!', 'Hi, World!'], 'HI world'))->toBe(Verdict::Correct);
});

// ---------------------------------------------------------------- what the review shows

it('shows the first variant as it was given, what was typed, and the words side by side', function (): void {
    $result = dictationGrader()->grade(['See you TOMORROW!', 'Bye'], '  see you tomorow  ');

    expect($result->reveal)->toBe([
        'expected' => 'See you TOMORROW!',
        'your_text' => 'see you tomorow',
        'diff' => [
            ['expected' => 'see', 'got' => 'see', 'ok' => true],
            ['expected' => 'you', 'got' => 'you', 'ok' => true],
            ['expected' => 'tomorrow', 'got' => 'tomorow', 'ok' => false],
        ],
        'translation' => null,
        'explanation' => null,
    ])
        ->and($result->normalizedAnswer)->toBe(['text' => 'see you tomorow'])
        ->and($result->final)->toBeTrue()
        ->and($result->firstAttempt)->toBeTrue()
        ->and($result->extra)->toBeNull();
});

it('puts no diff in the review when the answer has another number of words', function (): void {
    $result = dictationGrader()->grade(['She does not like tea'], 'She does like tea');

    expect($result->verdict)->toBe(Verdict::Wrong)
        ->and($result->reveal['diff'])->toBeNull()
        ->and($result->reveal['your_text'])->toBe('She does like tea');
});

it('aligns the diff with the variant that fits best, not with the first one', function (): void {
    // The answer has two words: the variant of two words is the one to compare with.
    $result = dictationGrader()->grade(['I am a student', 'Student'], 'Studnet');

    expect($result->verdict)->toBe(Verdict::Typo)
        ->and($result->reveal['expected'])->toBe('I am a student')
        ->and($result->reveal['diff'])->toBe([['expected' => 'student', 'got' => 'studnet', 'ok' => false]]);
});

it('marks every different word in the diff of a wrong answer of the same length', function (): void {
    $result = dictationGrader()->grade(['Where is the station'], 'Where was a station');

    expect($result->verdict)->toBe(Verdict::Wrong)
        ->and(array_column((array) $result->reveal['diff'], 'ok'))->toBe([true, false, false, true]);
});

it('keeps 200 characters of what was typed, and nothing of an empty answer', function (): void {
    $long = str_repeat('word ', 80);

    $result = dictationGrader()->grade(['See you tomorrow'], $long);
    expect(mb_strlen((string) $result->reveal['your_text']))->toBeLessThanOrEqual(200)
        ->and(mb_strlen((string) $result->normalizedAnswer['text']))->toBeLessThanOrEqual(200);

    $skipped = dictationGrader()->grade(['See you tomorrow'], '');
    expect($skipped->reveal['your_text'])->toBeNull()
        ->and($skipped->reveal['expected'])->toBe('See you tomorrow')
        ->and($skipped->reveal['diff'])->toBeNull();
});

// ---------------------------------------------------------------- the points of a battle

it('gives the points of a round only for a right answer: a typo, a mistake and a skip give none', function (): void {
    expect(dictationGrader()->grade(['See you tomorrow'], 'see you tomorrow')->points)->toBe(100)
        ->and(dictationGrader()->grade(['See you tomorrow'], 'see you tomorow')->points)->toBe(0)
        ->and(dictationGrader()->grade(['See you tomorrow'], 'see you later')->points)->toBe(0)
        ->and(dictationGrader()->grade(['See you tomorrow'], '')->points)->toBe(0);

    config(['practice.battle.points.round' => 60]);
    expect(dictationGrader()->grade(['See you tomorrow'], 'See you tomorrow')->points)->toBe(60);
});

// ---------------------------------------------------------------- InflectionGuard (docs/16 §14.1)

it('knows a change of the form of a word from a slip of the pen', function (string $a, string $e, bool $form): void {
    expect(InflectionGuard::isInflection($a, $e))->toBe($form)
        ->and(InflectionGuard::isInflection($e, $a))->toBe($form); // the order of the two does not matter
})->with([
    'likes / liked (b)' => ['likes', 'liked', true],
    'like / liked (a: d)' => ['like', 'liked', true],
    'coffee / coffees (a: s)' => ['coffee', 'coffees', true],
    'uses / used (b)' => ['uses', 'used', true],
    'live / lived (a: d)' => ['live', 'lived', true],
    'teacher / teachers (a: s)' => ['teacher', 'teachers', true],
    'friend / frien (a: d)' => ['friend', 'frien', true],
    'watches / watched (b)' => ['watches', 'watched', true],
    'walked / walkd is a slip' => ['walked', 'walkd', false],
    'receive / recieve is a slip' => ['receive', 'recieve', false],
    'tomorrow / tomorow is a slip' => ['tomorrow', 'tomorow', false],
    'a letter in the middle' => ['station', 'statin', false],
    'one other letter at the end' => ['water', 'watex', false],
    'e / s at the end (not s / d)' => ['likes', 'likee', false],
    'equal tokens' => ['same', 'same', false],
    'an added letter that is not s or d' => ['teacher', 'teachet', false],
]);

it('does not mistake a longer word for a form when more than one letter is added', function (): void {
    expect(InflectionGuard::isInflection('teach', 'teachers'))->toBeFalse()
        ->and(InflectionGuard::isInflection('like', 'likesd'))->toBeFalse();
});

it('works on empty tokens without an error', function (): void {
    expect(InflectionGuard::isInflection('', ''))->toBeFalse();
});
