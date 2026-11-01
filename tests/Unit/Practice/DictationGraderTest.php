<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Services\Grading\DictationGrader;
use App\Domain\Practice\Support\EnglishLexicon;
use App\Domain\Practice\Support\InflectionGuard;

/*
 * docs/16 §5.2: the verdicts of a dictation - the table of 38 cases of the document, the rules of a safe typo one by one, the
 * best verdict over the accepted variants, the review (`expected`, `your_text`, `diff`) - and InflectionGuard (§14.1).
 * A typo is a NON-word next to the right one: another word (`light` for `right`) or another form (`wrote` for `write`, `nicer` for
 * `nice`) is a mistake, and so is a final `s` / `d` added or dropped; a token that spells nothing (`stoped`, `makeing`, `mothe`) is a typo.
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
    '22 another word: late is a word (the pilot phrase)' => [['Call me later'], 'Call me late', 'wrong'],
    '23 another word: were is a word (the pilot phrase)' => [['Where is your father?', 'Where is your father'], 'Were is your father', 'wrong'],
    '24 another form: wrote / write, both words' => [['I wrote a letter'], 'I write a letter', 'wrong'],
    '25 another number: woman is a word' => [['The women are here'], 'The woman are here', 'wrong'],
    '26 another word: light for right' => [['Turn right here'], 'Turn light here', 'wrong'],
    '27 an ordinal is protected: fourth / forth' => [['The fourth floor'], 'The forth floor', 'wrong'],
    '28 another word: boots for books' => [['The books are on the table'], 'The boots are on the table', 'wrong'],
    '29 another form: nicer / nice (`nice` is a word)' => [['a nicer day'], 'a nice day', 'wrong'],
    '30 a slip: because' => [['because'], 'becuase', 'typo'],
    '31 a slip: friend' => [['My friend is here'], 'My freind is here', 'typo'],
    '32 a slip: family' => [['I love my family'], 'I love my famliy', 'typo'],
    '33 a slip: people' => [['Many people came'], 'Many peopel came', 'typo'],
    '34 a slip: beautiful' => [['It is beautiful'], 'It is beautifull', 'typo'],
    '35 a slip: cannot' => [['I cannot swim'], 'I canot swim', 'typo'],
    '36 a slip: Wednesday' => [['We meet on Wednesday'], 'We meet on Wendesday', 'typo'],
    '37 a slip: school' => [['I go to school'], 'I go to shcool', 'typo'],
    '38 a slip: today' => [['Call the client today'], 'Call the client todya', 'typo'],
]);

it('calls another WORD one edit away a mistake in either direction: the typed token is a word', function (string $one, string $other): void {
    // Each pair is two English words one edit apart; whichever is dictated, the other is not a slip of the pen.
    expect(dictationVerdict([$one], $other))->not->toBe(Verdict::Typo)
        ->and(dictationVerdict([$other], $one))->not->toBe(Verdict::Typo)
        ->and(dictationVerdict(["Say {$one} twice"], "Say {$other} twice"))->toBe(Verdict::Wrong)
        ->and(dictationVerdict(["Say {$other} twice"], "Say {$one} twice"))->toBe(Verdict::Wrong);
})->with([
    'right / light' => ['right', 'light'],
    'write / white' => ['write', 'white'],
    'quiet / quite' => ['quiet', 'quite'],
    'where / were' => ['where', 'were'],
    'think / thank' => ['think', 'thank'],
    'shirt / short' => ['shirt', 'short'],
    'glass / grass' => ['glass', 'grass'],
    'these / those' => ['these', 'those'],
    'angle / angel' => ['angle', 'angel'],
    'clothes / cloths' => ['clothes', 'cloths'],
    'books / boots' => ['books', 'boots'],
    'walker / walked' => ['walker', 'walked'],
    'teacher / teaches' => ['teacher', 'teaches'],
    'played / player' => ['played', 'player'],
    'wrote / write' => ['wrote', 'write'],
    'women / woman' => ['women', 'woman'],
    'later / late' => ['later', 'late'],
    'taken / take' => ['taken', 'take'],
    'given / give' => ['given', 'give'],
    'nicer / nice' => ['nicer', 'nice'],
    'larger / large' => ['larger', 'large'],
    'chosen / chose' => ['chosen', 'chose'],
    'chose / choose' => ['chose', 'choose'],
    'drank / drink' => ['drank', 'drink'],
    'began / begin' => ['began', 'begin'],
    'became / become' => ['became', 'become'],
    'forgot / forget' => ['forgot', 'forget'],
    'quiet / quit' => ['quiet', 'quit'],
    'brought / bought' => ['brought', 'bought'],
    'weather / feather' => ['weather', 'feather'],
    'water / waiter' => ['water', 'waiter'],
    'dinner / winner' => ['dinner', 'winner'],
    'rainy / rains' => ['rainy', 'rains'],
    'later / latter' => ['later', 'latter'],
    'baker / baked' => ['baker', 'baked'],
    'taken / token' => ['taken', 'token'],
    'boots / boats' => ['boots', 'boats'],
    'hoping / hopping' => ['hoping', 'hopping'],
    'sitting / setting' => ['sitting', 'setting'],
]);

it('calls a change of the form of a word a mistake without the guard: the typed token is a word of the lexicon', function (string $expected, string $typed): void {
    expect(InflectionGuard::isInflection($typed, $expected))->toBeFalse()                    // the guard looks at `s` / `d` only
        ->and(dictationVerdict(["Say {$expected} twice"], "Say {$typed} twice"))->toBe(Verdict::Wrong);
})->with([
    'nice / nicer' => ['nicer', 'nice'],
    'take / taken' => ['taken', 'take'],
    'rain / rainy' => ['rainy', 'rain'],
    'later / late' => ['later', 'late'],
    'walker / walked' => ['walker', 'walked'],
    'player / played' => ['player', 'played'],
    'given / give' => ['given', 'give'],
    'large / larger' => ['large', 'larger'],
]);

it('keeps the ordinals out of the forgiven words, as the numerals are', function (string $ordinal, string $slip): void {
    expect(dictationVerdict(["the {$ordinal} floor"], "the {$slip} floor"))->toBe(Verdict::Wrong)
        ->and(dictationVerdict(["the {$slip} floor"], "the {$ordinal} floor"))->toBe(Verdict::Wrong);
})->with([
    'fourth' => ['fourth', 'forth'],
    'fifth' => ['fifth', 'fifht'],
    'sixth' => ['sixth', 'sixt'],
    'eighth' => ['eighth', 'eigth'],
    'ninth' => ['ninth', 'nineth'],
    'twelfth' => ['twelfth', 'twelth'],
    'twentieth' => ['twentieth', 'twentith'],
    'hundredth' => ['hundredth', 'hundreth'],
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

// ---------------------------------------------------------------- a slip that spells nothing is a typo (docs/15 §3.4, 18.11a)

it('forgives the slips of an ending: the doubling left out or put in, the silent e kept, the wrong y - the typed token spells nothing', function (string $expected, string $typed): void {
    expect(dictationVerdict([$expected], $typed))->toBe(Verdict::Typo)
        ->and(dictationVerdict(["Say {$expected} twice"], "Say {$typed} twice"))->toBe(Verdict::Typo);
})->with([
    // the doubling left out
    'stopped' => ['stopped', 'stoped'],
    'running' => ['running', 'runing'],
    'beginning' => ['beginning', 'begining'],
    'swimming' => ['swimming', 'swiming'],
    'getting' => ['getting', 'geting'],
    'bigger' => ['bigger', 'biger'],
    'hotter' => ['hotter', 'hoter'],
    'shopping' => ['shopping', 'shoping'],
    'occurred' => ['occurred', 'occured'],
    'preferred' => ['preferred', 'prefered'],
    'putting' => ['putting', 'puting'],
    'cutting' => ['cutting', 'cuting'],
    'dropped' => ['dropped', 'droped'],
    'chatting' => ['chatting', 'chating'],
    'stopping' => ['stopping', 'stoping'],
    'biggest' => ['biggest', 'bigest'],
    'hottest' => ['hottest', 'hotest'],
    // the silent e kept
    'making' => ['making', 'makeing'],
    'writing' => ['writing', 'writeing'],
    'coming' => ['coming', 'comeing'],
    'having' => ['having', 'haveing'],
    'giving' => ['giving', 'giveing'],
    'living' => ['living', 'liveing'],
    'taking' => ['taking', 'takeing'],
    'using' => ['using', 'useing'],
    'dancing' => ['dancing', 'danceing'],
    'driving' => ['driving', 'driveing'],
    // the consonant doubled where it is not
    'meeting' => ['meeting', 'meetting'],
    'eating' => ['eating', 'eatting'],
    'visited' => ['visited', 'visitted'],
    'opened' => ['opened', 'openned'],
    'looking' => ['looking', 'lookking'],
    // the y
    'played' => ['played', 'plaied'],
    'happier' => ['happier', 'happyer'],
]);

it('keeps the ordinary slips a typo: a letter left out, swapped or doubled where the token spells nothing', function (string $expected, string $typed): void {
    expect(dictationVerdict([$expected], $typed))->toBe(Verdict::Typo);
})->with([
    'because' => ['because', 'becuase'],
    'friend' => ['friend', 'freind'],
    'receive' => ['receive', 'recieve'],
    'tomorrow' => ['tomorrow', 'tomorow'],
    'beautiful' => ['beautiful', 'beatiful'],
    'really' => ['really', 'realy'],
    'finally' => ['finally', 'finaly'],
    'until' => ['until', 'untill'],
    'which' => ['which', 'wich'],
    'their' => ['their', 'thier'],
    'different' => ['different', 'diferent'],
    'address' => ['address', 'adress'],
    'necessary' => ['necessary', 'neccessary'],
    'always' => ['always', 'allways'],
    'coffee' => ['coffee', 'cofee'],
    'sister' => ['sister', 'sistr'],
    'mother' => ['mother', 'mohter'],
    'father' => ['father', 'fahter'],
    'brother' => ['brother', 'brothr'],
    'family' => ['family', 'famly'],
    'school' => ['school', 'scool'],
    'teacher' => ['teacher', 'teaher'],
    'morning' => ['morning', 'mornig'],
    'evening' => ['evening', 'evenig'],
    'breakfast' => ['breakfast', 'brekfast'],
    'february' => ['february', 'febuary'],
    'library' => ['library', 'libary'],
    'probably' => ['probably', 'probaly'],
    'usually' => ['usually', 'usualy'],
]);

it('keeps a slip of two letters a mistake, whatever it spells: wensday for Wednesday', function (): void {
    expect(dictationVerdict(['wednesday'], 'wensday'))->toBe(Verdict::Wrong);
});

it('keeps a slip a mistake when the typed token is a word after all: another spelling, a verb that has a twin, a form of another lemma', function (string $expected, string $typed, string $why): void {
    expect(EnglishLexicon::isWord($typed))->toBeTrue($why)
        ->and(dictationVerdict([$expected], $typed))->toBe(Verdict::Wrong);
})->with([
    'sitting / siting' => ['sitting', 'siting', 'site is a verb: siting'],
    'planned / planed' => ['planned', 'planed', 'plane is a verb: planed'],
    'travelling / traveling' => ['travelling', 'traveling', 'the American spelling'],
    'hopping / hoping' => ['hopping', 'hoping', 'hope is a verb: hoping'],
]);

it('keeps a slip a mistake when it is two edits away: the form is not one edit from the right one', function (string $expected, string $typed): void {
    // `studies` -> `studys`: i -> y and the e is lost. The lexicon knows it is no word, but OSA is 2, so it is no typo of the dictation.
    expect(EnglishLexicon::isWord($typed))->toBeFalse()
        ->and(dictationVerdict([$expected], $typed))->toBe(Verdict::Wrong);
})->with([
    'studies / studys' => ['studies', 'studys'],
    'families / familys' => ['families', 'familys'],
    'cities / citys' => ['cities', 'citys'],
]);

it('forgives a slip at the last letter of a token that spells nothing: mothe, Monda, famil, happ, kitche', function (string $expected, string $typed): void {
    expect(dictationVerdict([$expected], $typed))->toBe(Verdict::Typo);
})->with([
    'a doubled last letter: mother' => ['mother', 'motherr'],
    'a doubled last letter: father' => ['father', 'fatherr'],
    'a doubled last letter: dinner' => ['dinner', 'dinnerr'],
    'a doubled last letter: children' => ['children', 'childrenn'],
    'a doubled last letter: happy' => ['happy', 'happyy'],
    'a doubled last letter: kitchen' => ['kitchen', 'kitchenn'],
    'a doubled last letter: family' => ['family', 'familyy'],
    'a doubled last letter: garden' => ['garden', 'gardenn'],
    'the last letter hit by its neighbour: children' => ['children', 'childred'],
    'the last letter hit by its neighbour: kitchen' => ['kitchen', 'kitches'],
    'the last letter hit by its neighbour: garden' => ['garden', 'gardes'],
    'the last letter hit by its neighbour: water' => ['water', 'wated'],
    'the last letter hit by its neighbour: doctor' => ['doctor', 'doctod'],
    'the last letter hit by its neighbour: Monday' => ['Monday', 'Mondan'],
    'the last letter hit by its neighbour: Sunday' => ['Sunday', 'Sundar'],
    'the last letter hit by its neighbour: brown' => ['brown', 'browd'],
    'the last letter hit by its neighbour: woman' => ['woman', 'womas'],
    'the last letter dropped: mother' => ['mother', 'mothe'],
    'the last letter dropped: happy' => ['happy', 'happ'],
    'the last letter dropped: kitchen' => ['kitchen', 'kitche'],
    'the last letter dropped: dinner' => ['dinner', 'dinne'],
    'the last letter dropped: family' => ['family', 'famil'],
    'the last letter dropped: Friday' => ['Friday', 'Frida'],
    'the last letter dropped: Sunday' => ['Sunday', 'Sunda'],
    'the last letter dropped: together' => ['together', 'togethe'],
    'the last letter dropped: sister' => ['sister', 'siste'],
]);

it('keeps the tails s and d of the guard, and what the guard cannot tell from a form stays a mistake on purpose: frien, friendd, alwayss', function (string $expected, string $typed): void {
    // «frien» for «friend» is «friend» without the tail d (docs/16 §5.2: a doubtful case is a mistake); a doubled s / d is the same tail to the rule.
    expect(dictationVerdict([$expected], $typed))->toBe(Verdict::Wrong);
})->with([
    'frien' => ['friend', 'frien'],
    'friendd' => ['friend', 'friendd'],
    'alwayss' => ['always', 'alwayss'],
    'teacher / teachers' => ['teacher', 'teachers'],
    'likes / liked' => ['likes', 'liked'],
]);

it('keeps a numeral a mistake even when the typed token spells nothing: seves for seven', function (): void {
    expect(dictationVerdict(['seven'], 'seves'))->toBe(Verdict::Wrong);
});

it('forgives the slips in the phrases of the pilot pack, «The meting is at ten» first', function (string $expected, string $typed): void {
    expect(dictationVerdict([$expected], $typed))->toBe(Verdict::Typo);
})->with([
    'meeting' => ['The meeting is at ten', 'The meting is at ten'],
    'morning' => ['Good morning', 'Good mornig'],
    'evening' => ['Have a nice evening', 'Have a nice evning'],
    'mother' => ['This is my mother', 'This is my mothe'],
    'Friday' => ['Today is Friday', 'Today is Frida'],
    'Sunday' => ['We meet on Sunday', 'We meet on Sunda'],
    'together' => ['We walk together', 'We walk togethe'],
    'sister' => ['I have one sister', 'I have one siste'],
    'family' => ['I love my family', 'I love my famly'],
    'brother' => ['Her brother is tall', 'Her brothr is tall'],
    'lives' => ['My grandmother lives here', 'My grandmother livs here'],
    'there' => ['There is a cat', 'Ther is a cat'],
    'these' => ['These are my books', 'Thes are my books'],
    'honest' => ['He is an honest man', 'He is an onest man'],
]);

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
    'friend / friendd (a: d, a doubled letter: the tail is all the rule sees)' => ['friend', 'friendd', true],
    'always / alwayss (a: s, the same)' => ['always', 'alwayss', true],
    'watches / watched (b)' => ['watches', 'watched', true],
    'walked / walkd is a slip' => ['walked', 'walkd', false],
    'receive / recieve is a slip' => ['receive', 'recieve', false],
    'tomorrow / tomorow is a slip' => ['tomorrow', 'tomorow', false],
    'a letter in the middle' => ['station', 'statin', false],
    'one other letter at the end' => ['water', 'watex', false],
    'e / s at the end (not s / d)' => ['likes', 'likee', false],
    'equal tokens' => ['same', 'same', false],
    'an added letter that is not s or d' => ['teacher', 'teachet', false],
    'a last letter that is not s or d (x / s)' => ['rains', 'rainx', false],
    'two different words, however their last letters' => ['rains', 'daily', false],
    // r, n, y were tails of the guard in 18.9b; they are not any more: the typed token of such a pair is a word, and the lexicon says so.
    'nice / nicer: r is not a tail' => ['nice', 'nicer', false],
    'take / taken: n is not a tail' => ['take', 'taken', false],
    'rain / rainy: y is not a tail' => ['rain', 'rainy', false],
    'mother / mothe: the slip of a non-word' => ['mother', 'mothe', false],
    'Monday / Monda' => ['monday', 'monda', false],
    'family / famil' => ['family', 'famil', false],
    'happy / happ' => ['happy', 'happ', false],
    'kitchen / kitche' => ['kitchen', 'kitche', false],
    'walker / walked: r / d is not s / d' => ['walker', 'walked', false],
    'taker / taken: r / n' => ['taker', 'taken', false],
    'rains / rainy: s / y' => ['rains', 'rainy', false],
    'teacher / teaches: r / s' => ['teacher', 'teaches', false],
    'played / player: d / r' => ['played', 'player', false],
    'children / childred: d / n' => ['children', 'childred', false],
    'given / give: n is not a tail' => ['given', 'give', false],
    'the same last letter' => ['teachers', 'teachers', false],
]);

it('does not mistake a longer word for a form when more than one letter is added', function (): void {
    expect(InflectionGuard::isInflection('teach', 'teachers'))->toBeFalse()
        ->and(InflectionGuard::isInflection('like', 'likesd'))->toBeFalse()
        ->and(InflectionGuard::isInflection('rain', 'rainsd'))->toBeFalse();
});

it('works on empty tokens without an error', function (): void {
    expect(InflectionGuard::isInflection('', ''))->toBeFalse();
});
