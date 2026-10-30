<?php

declare(strict_types=1);

use App\Domain\Practice\Support\EnglishLexicon;

/*
 * docs/16 §5.2: the lexicon the dictation asks about the TYPED word - the file `resources/lexicon/en-common.txt` (one lower-case word
 * to a line, sorted, no repeats), the irregular forms it must list, the rules of English spelling that turn a form into a lemma,
 * the real slips that must NOT be taken for words, and the speed of a lookup.
 */

/** @return list<string> the words of the file, in the order of the file (the comment lines are not words) */
function lexiconFileWords(): array
{
    $lines = file(resource_path(EnglishLexicon::FILE), FILE_IGNORE_NEW_LINES);
    expect($lines)->toBeArray();

    return array_values(array_filter((array) $lines, static fn (string $line): bool => ! str_starts_with($line, '#')));
}

// ---------------------------------------------------------------- the file

it('is a text file of 2500 words at least, with a comment on what it is for at the top', function (): void {
    $lines = (array) file(resource_path(EnglishLexicon::FILE), FILE_IGNORE_NEW_LINES);

    expect(count(lexiconFileWords()))->toBeGreaterThanOrEqual(2500)
        ->and($lines[0])->toStartWith('#')
        ->and(implode("\n", array_slice($lines, 0, 10)))->toContain('dictation', 'EnglishLexicon');
});

it('keeps the file clean: no blank line, lower case, letters and the apostrophe only, no spaces round a word', function (): void {
    $lines = (array) file(resource_path(EnglishLexicon::FILE), FILE_IGNORE_NEW_LINES);
    $offences = [];
    foreach ($lines as $number => $line) {
        if ($line === '') {
            $offences[] = 'line '.($number + 1).' is blank';
        } elseif (str_starts_with($line, '#')) {
            continue;
        } elseif ($line !== trim($line) || mb_strtolower($line) !== $line || preg_match("/\\A[a-zé']+\\z/u", $line) !== 1) {
            $offences[] = 'line '.($number + 1).": «{$line}»";
        }
    }

    expect($offences)->toBe([]);
});

it('is sorted in byte order, without a word twice', function (): void {
    $words = lexiconFileWords();
    $offences = [];
    foreach ($words as $index => $word) {
        if ($index > 0 && strcmp($words[$index - 1], $word) >= 0) {
            $offences[] = "«{$words[$index - 1]}» before «{$word}»";
        }
    }

    expect($offences)->toBe([])
        ->and(count(array_unique($words)))->toBe(count($words));
});

it('has no comment line among the words and no word that is a misspelling of a common one', function (): void {
    $words = array_flip(lexiconFileWords());

    // The slips the dictation must still forgive: none may be on the list, nor a regular form of a word on it.
    foreach (lexiconSlips() as $slip) {
        expect(isset($words[$slip]))->toBeFalse("«{$slip}» is on the list");
    }
    expect(array_filter(array_keys($words), static fn (string $word): bool => str_starts_with($word, '#')))->toBe([]);
});

// ---------------------------------------------------------------- the forms the list must have

/** @return array<string, list<string>> the irregular verbs: the forms that no rule of spelling makes out of the lemma */
function lexiconIrregularVerbs(): array
{
    $table = <<<'TXT'
arise arose arisen|awake awoke awoken|be am is are was were been|bear bore born borne|beat beaten|become became|begin began begun|bend bent|bet|bid|bind bound
bite bit bitten|bleed bled|blow blew blown|break broke broken|breed bred|bring brought|broadcast|build built|burn burnt|burst|buy bought|cast|catch caught
choose chose chosen|cling clung|come came|cost|creep crept|cut|deal dealt|dig dug|do did done does|draw drew drawn|dream dreamt|drink drank drunk
drive drove driven|dwell dwelt|eat ate eaten|fall fell fallen|feed fed|feel felt|fight fought|find found|flee fled|fling flung|fly flew flown flies
forbid forbade forbidden|forecast|foresee foresaw|forget forgot forgotten|forgive forgave forgiven|freeze froze frozen|get got gotten|give gave given
go went gone goes|grind ground|grow grew grown|hang hung|have had has|hear heard|hide hid hidden|hit|hold held|hurt|keep kept|kneel knelt|know knew known
lay laid|lead led|lean leant|leap leapt|learn learnt|leave left|lend lent|let|lie lay lain|light lit|lose lost|make made|mean meant|meet met
mistake mistook mistaken|overcome overcame|pay paid|put|quit|read|ride rode ridden|ring rang rung|rise rose risen|run ran|say said says|see saw seen
seek sought|sell sold|send sent|set|sew sewn|shake shook shaken|shine shone|shoot shot|show shown|shrink shrank shrunk|shut|sing sang sung|sink sank sunk
sit sat|sleep slept|slide slid|speak spoke spoken|spell spelt|spend spent|spill spilt|spin spun|spit spat|split|spoil spoilt|spread|spring sprang sprung
stand stood|steal stole stolen|stick stuck|sting stung|stink stank|strike struck|swear swore sworn|sweep swept|swim swam swum|swing swung|take took taken
teach taught|tear tore torn|tell told|think thought|throw threw thrown|tread trod|understand understood|undo undid undone|wake woke woken|wear wore worn
weep wept|win won|wind wound|withdraw withdrew withdrawn|write wrote written
TXT;

    $verbs = [];
    foreach (explode('|', str_replace("\n", '|', $table)) as $row) {
        $forms = preg_split('/\s+/', trim($row)) ?: [];
        if ($forms !== [] && $forms[0] !== '') {
            $verbs[$forms[0]] = $forms;
        }
    }

    return $verbs;
}

it('knows ALL the forms of about 150 irregular verbs', function (): void {
    $verbs = lexiconIrregularVerbs();
    expect(count($verbs))->toBeGreaterThanOrEqual(140);

    $missing = [];
    foreach ($verbs as $lemma => $forms) {
        foreach ($forms as $form) {
            if (! EnglishLexicon::isWord($form)) {
                $missing[] = "{$lemma}: {$form}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('lists the irregular forms that no rule can make: the past and the participle are in the file itself', function (): void {
    $words = array_flip(lexiconFileWords());
    $missing = [];
    foreach (['wrote', 'written', 'drank', 'drunk', 'began', 'begun', 'became', 'forgot', 'forgotten', 'chose', 'chosen', 'took', 'taken', 'gave', 'given',
        'went', 'gone', 'saw', 'seen', 'ate', 'eaten', 'ran', 'sang', 'sung', 'swam', 'swum', 'thought', 'bought', 'brought', 'taught', 'caught', 'was', 'were', 'been'] as $form) {
        if (! isset($words[$form])) {
            $missing[] = $form;
        }
    }

    expect($missing)->toBe([]);
});

it('knows the irregular plurals and the irregular degrees of comparison', function (string $word): void {
    expect(EnglishLexicon::isWord($word))->toBeTrue();
})->with([
    ...array_map(static fn (string $w): array => [$w], [
        'men', 'women', 'children', 'people', 'feet', 'teeth', 'mice', 'geese', 'oxen', 'lice', 'wives', 'knives', 'lives', 'leaves', 'loaves', 'shelves',
        'wolves', 'thieves', 'halves', 'selves', 'calves', 'elves', 'scarves', 'fish', 'sheep', 'deer', 'series', 'species', 'police', 'news', 'data',
        'criteria', 'phenomena', 'analyses', 'crises', 'cacti', 'fungi', 'bacteria',
        'better', 'best', 'worse', 'worst', 'more', 'most', 'less', 'least', 'further', 'furthest', 'farther', 'farthest', 'elder', 'eldest',
    ]),
]);

it('lists the irregular plurals and degrees in the file itself', function (): void {
    $words = array_flip(lexiconFileWords());

    foreach (['women', 'children', 'people', 'feet', 'teeth', 'mice', 'better', 'best', 'worse', 'worst', 'more', 'most', 'less', 'least', 'further'] as $word) {
        expect(isset($words[$word]))->toBeTrue("«{$word}» is not in the file");
    }
});

it('knows the function words, the numerals with their ordinals, the days and the months', function (): void {
    $known = ['the', 'a', 'an', 'of', 'because', 'would', "don't", "i'm", "it's", "o'clock", 'fifteen', 'fifteenth', 'ninetieth', 'hundredth', 'thousandth',
        'monday', 'sunday', 'january', 'december', 'tomorrow', 'yesterday', 'morning', 'evening'];

    foreach ($known as $word) {
        expect(EnglishLexicon::isWord($word))->toBeTrue("«{$word}»");
    }
});

// ---------------------------------------------------------------- the rules of spelling

it('turns a regular form back into its lemma by the rules of English spelling', function (string $form, string $lemma): void {
    expect(EnglishLexicon::lemmas($form))->toContain($lemma)
        ->and(EnglishLexicon::isWord($form))->toBeTrue();
})->with([
    'possessive' => ["mother's", 'mother'],
    'plural -s' => ['cats', 'cat'],
    'third person -s' => ['runs', 'run'],
    '-es after s' => ['buses', 'bus'],
    '-es after x' => ['boxes', 'box'],
    '-es after z' => ['jazzes', 'jazz'],
    '-es after ch' => ['watches', 'watch'],
    '-es after sh' => ['wishes', 'wish'],
    '-es after o' => ['goes', 'go'],
    '-ies' => ['studies', 'study'],
    '-ies of a noun' => ['cities', 'city'],
    '-ed' => ['walked', 'walk'],
    '-d after e' => ['liked', 'like'],
    '-d after ee' => ['agreed', 'agree'],
    'a doubled consonant + ed' => ['stopped', 'stop'],
    'a doubled consonant + ed (planned)' => ['planned', 'plan'],
    '-ied' => ['tried', 'try'],
    '-ied (studied)' => ['studied', 'study'],
    '-ing' => ['walking', 'walk'],
    '-ing + e' => ['making', 'make'],
    '-ing + e (hoping)' => ['hoping', 'hope'],
    'a doubled consonant + ing' => ['running', 'run'],
    'a doubled consonant + ing (sitting)' => ['sitting', 'sit'],
    '-ying' => ['lying', 'lie'],
    '-ying (dying)' => ['dying', 'die'],
    '-er' => ['taller', 'tall'],
    '-r after e' => ['nicer', 'nice'],
    'a doubled consonant + er' => ['bigger', 'big'],
    '-ier' => ['happier', 'happy'],
    '-est' => ['tallest', 'tall'],
    '-st after e' => ['nicest', 'nice'],
    'a doubled consonant + est' => ['biggest', 'big'],
    '-iest' => ['happiest', 'happy'],
    '-ly' => ['quickly', 'quick'],
    '-ily' => ['happily', 'happy'],
]);

it('does not undo a suffix that is not there, and leaves no stem shorter than two letters', function (): void {
    expect(EnglishLexicon::lemmas('cat'))->toBe([])
        ->and(EnglishLexicon::lemmas('as'))->toBe([])          // «a» + s is no reason to call anything a form
        ->and(EnglishLexicon::lemmas('ed'))->toBe([])
        ->and(EnglishLexicon::lemmas('ing'))->toBe([])
        ->and(EnglishLexicon::lemmas(''))->toBe([]);
});

it('applies one rule and one only: a form of a form is no form', function (): void {
    // «walkers» is «walker» + s and «walker» is «walk» + er: the list has «walk», not «walker», in this test of the rules alone.
    expect(EnglishLexicon::lemmas('walkers'))->toContain('walker')
        ->and(EnglishLexicon::lemmas('walkers'))->not->toContain('walk');
});

it('errs on the strict side: a slip that the rules happen to read as a form is called a word, never the other way round', function (): void {
    // «begin» + ing, «occur» + ed, «meet» + ing with a doubled t: the right spellings double the consonant, but the check only gets stricter.
    expect(EnglishLexicon::isWord('begining'))->toBeTrue()
        ->and(EnglishLexicon::isWord('occured'))->toBeTrue()
        ->and(EnglishLexicon::isWord('meetting'))->toBeTrue();
});

it('reads any case and the typographic apostrophe', function (): void {
    expect(EnglishLexicon::isWord('Light'))->toBeTrue()
        ->and(EnglishLexicon::isWord('LIGHT'))->toBeTrue()
        ->and(EnglishLexicon::isWord(" light\n"))->toBeTrue()
        ->and(EnglishLexicon::isWord("mother\u{2019}s"))->toBeTrue()
        ->and(EnglishLexicon::isWord("don\u{2019}t"))->toBeTrue();
});

// ---------------------------------------------------------------- what is not a word

/** @return list<string> the slips of the pen a learner makes; none of them spells an English word */
function lexiconSlips(): array
{
    return ['becuase', 'freind', 'famliy', 'peopel', 'beautifull', 'canot', 'wendesday', 'shcool', 'todya', 'recieve', 'tomorow', 'beatiful', 'wich',
        'definately', 'seperate', 'untill', 'adress', 'enviroment', 'goverment', 'comming', 'writting', 'diffrent', 'interesing', 'responsable',
        'thier', 'wierd', 'beleive', 'calender', 'neccessary', 'embarass', 'accomodation', 'suprise', 'tommorow', 'wensday', 'thursdey', 'saturdey',
        'sunnday', 'fridey', 'januery', 'febuary', 'sepember', 'decmber', 'brithday', 'grandmohter', 'sistre', 'brohter', 'mohter', 'fahter', 'computr',
        'projct', 'offcie', 'cliant', 'reprot', 'redy', 'evning', 'mornig'];
}

it('does not take a slip of the pen for a word', function (string $slip): void {
    expect(EnglishLexicon::isWord($slip))->toBeFalse();
})->with(array_map(static fn (string $slip): array => [$slip], lexiconSlips()));

it('says no to nothing, to blanks, to digits and to marks', function (string $token): void {
    expect(EnglishLexicon::isWord($token))->toBeFalse();
})->with([[''], ['   '], ['123'], ['10th'], ['...'], ["'"], ["'s"], ['xqzvk']]);

// ---------------------------------------------------------------- speed

it('answers a thousand questions in less than 50 ms once the list is loaded', function (): void {
    EnglishLexicon::isWord('warm');   // the list is read here, once per process

    $tokens = ['light', 'becuase', 'walked', 'freind', 'studies', 'peopel', 'biggest', 'todya', 'wrote', 'shcool'];
    $start = hrtime(true);
    $found = 0;
    for ($i = 0; $i < 1000; $i++) {
        $found += EnglishLexicon::isWord($tokens[$i % 10]) ? 1 : 0;
    }
    $milliseconds = (hrtime(true) - $start) / 1_000_000;

    expect($found)->toBe(500)
        ->and($milliseconds)->toBeLessThan(50.0);
});
