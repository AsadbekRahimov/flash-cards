<?php

declare(strict_types=1);

use App\Domain\Practice\Support\EnglishLexicon;

/*
 * docs/16 §5.2: the lexicon the dictation asks about the TYPED word - the two files (`en-common.txt`: the lemmas, the only bases of the
 * rules; `en-irregular.txt`: the irregular forms, words but never bases), the irregular forms they must list, the rules of English
 * SPELLING that turn a lemma into its forms (a misspelt form is no word), the real slips that must NOT be taken for words, and the speed
 * of a lookup.
 */

dataset('lexiconFiles', [
    'en-common.txt' => [EnglishLexicon::FILE, 2500],
    'en-irregular.txt' => [EnglishLexicon::IRREGULAR_FILE, 200],
]);

/** @return list<string> the words of one file, in the order of the file (the comment lines are not words) */
function lexiconFileWords(string $file = EnglishLexicon::FILE): array
{
    $lines = file(resource_path($file), FILE_IGNORE_NEW_LINES);
    expect($lines)->toBeArray();

    return array_values(array_filter((array) $lines, static fn (string $line): bool => ! str_starts_with($line, '#')));
}

// ---------------------------------------------------------------- the files

it('is a text file with enough words and a comment on what it is for at the top', function (string $file, int $least): void {
    $lines = (array) file(resource_path($file), FILE_IGNORE_NEW_LINES);

    expect(count(lexiconFileWords($file)))->toBeGreaterThanOrEqual($least)
        ->and($lines[0])->toStartWith('#')
        ->and(implode("\n", array_slice($lines, 0, 10)))->toContain('dictation', 'EnglishLexicon');
})->with('lexiconFiles');

it('keeps the file clean: no blank line, lower case, letters and the apostrophe only, no spaces round a word', function (string $file): void {
    $lines = (array) file(resource_path($file), FILE_IGNORE_NEW_LINES);
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
})->with('lexiconFiles');

it('is sorted in byte order, without a word twice', function (string $file): void {
    $words = lexiconFileWords($file);
    $offences = [];
    foreach ($words as $index => $word) {
        if ($index > 0 && strcmp($words[$index - 1], $word) >= 0) {
            $offences[] = "«{$words[$index - 1]}» before «{$word}»";
        }
    }

    expect($offences)->toBe([])
        ->and(count(array_unique($words)))->toBe(count($words));
})->with('lexiconFiles');

it('keeps a word in one file only: a lemma is a base, an irregular form is not, and nothing is both', function (): void {
    $both = array_intersect(lexiconFileWords(), lexiconFileWords(EnglishLexicon::IRREGULAR_FILE));

    expect(array_values($both))->toBe([]);
});

it('has no comment line among the words and no word that is a misspelling of a common one', function (): void {
    $words = array_flip([...lexiconFileWords(), ...lexiconFileWords(EnglishLexicon::IRREGULAR_FILE)]);

    // The slips the dictation must still forgive: none may be on a list, nor a correctly spelled form of a lemma.
    foreach ([...lexiconSlips(), ...lexiconSuffixSlips()] as $slip) {
        expect(isset($words[$slip]))->toBeFalse("«{$slip}» is on a list");
    }
    expect(array_filter(array_keys($words), static fn (string $word): bool => str_starts_with($word, '#')))->toBe([]);
});

// ---------------------------------------------------------------- the forms the lists must have

/** @return array<string, list<string>> the irregular verbs: the lemma first, then the forms that no rule of spelling makes out of it */
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

it('lists the irregular forms in the irregular file, where no rule starts from them, and not among the lemmas', function (): void {
    $verbs = lexiconIrregularVerbs();
    $lemmas = array_flip(array_keys($verbs));
    // Forms a rule makes anyway, and forms that are ALSO lemmas with regular forms of their own (`founded`, `grounded`, `roses`, `sawing`).
    $stay = array_flip(['does', 'goes', 'flies', 'says', 'found', 'bound', 'ground', 'wound', 'saw', 'rose', 'bore', 'lay', 'spoke']);
    $irregular = array_flip(lexiconFileWords(EnglishLexicon::IRREGULAR_FILE));
    $common = array_flip(lexiconFileWords());

    $misplaced = [];
    foreach ($verbs as $lemma => $forms) {
        foreach (array_slice($forms, 1) as $form) {
            if (isset($stay[$form]) || isset($lemmas[$form])) {
                continue;
            }
            if (! isset($irregular[$form]) || isset($common[$form])) {
                $misplaced[] = "{$lemma}: {$form}";
            }
        }
    }

    expect($misplaced)->toBe([])
        ->and(isset($common['write'], $common['take'], $common['meet']))->toBeTrue();   // the lemmas stay where the rules start from
});

it('lists the lemmas that have regular forms of their own among the lemmas, so that `founded`, `grounded` and `roses` are words', function (string $form): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue();
})->with(['founded', 'founding', 'bounded', 'grounded', 'wounded', 'wounds', 'roses', 'saws', 'sawed', 'sawing', 'bores', 'lays', 'laying', 'spokes', 'rented', 'renting']);

it('never starts a rule from an irregular form: `met` is a word, `meting` and `metting` are not `met` + `ing`', function (string $form, string $notForm): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue()
        ->and(EnglishLexicon::isWord($notForm))->toBeFalse();
})->with([
    'met' => ['met', 'meting'],
    'met, doubled' => ['met', 'metting'],
    'went' => ['went', 'wenting'],
    'went + ed' => ['went', 'wented'],
    'wrote' => ['wrote', 'wroting'],
    'ran' => ['ran', 'ranning'],
    'sat' => ['sat', 'satting'],
    'got' => ['got', 'gotting'],
    'had' => ['had', 'hading'],
    'fed' => ['fed', 'fedding'],
    'hid' => ['hid', 'hidding'],
    'led' => ['led', 'ledding'],
    'won' => ['won', 'wonning'],
    'was' => ['was', 'wasing'],
    'bit' => ['bit', 'bitting'],
    'men' => ['men', 'mens'],
    'women' => ['women', 'womens'],
    'feet' => ['feet', 'feets'],
    'children' => ['children', 'childrens'],
    'better' => ['better', 'betterer'],
    'worse' => ['worse', 'worser'],
    'taken' => ['taken', 'takens'],
]);

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

it('lists the irregular plurals and degrees in the irregular file itself', function (): void {
    $words = array_flip(lexiconFileWords(EnglishLexicon::IRREGULAR_FILE));

    foreach (['women', 'children', 'people', 'feet', 'teeth', 'mice', 'knives', 'wolves', 'criteria', 'better', 'best', 'worse', 'worst', 'more', 'most', 'less', 'least', 'further'] as $word) {
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

it('puts no ending on a function word: `ther` is not `the` + `r`, `onest` is not `one` + `st`, `againest` is not `again` + `est`', function (string $word, string $slip): void {
    expect(EnglishLexicon::isWord($word))->toBeTrue()
        ->and(EnglishLexicon::isWord($slip))->toBeFalse();
})->with([
    'the + r' => ['the', 'ther'],
    'the + s' => ['the', 'thes'],
    'one + st' => ['one', 'onest'],
    'one + r' => ['one', 'oner'],
    'again + est' => ['again', 'againest'],
    'away + s' => ['away', 'aways'],
    'at + er' => ['at', 'ater'],
    'he + st' => ['he', 'hest'],
    'she + s' => ['she', 'shes'],
    'of + er' => ['of', 'ofer'],
    'up + er' => ['up', 'uper'],
    'in + er' => ['in', 'iner'],
    'you + s' => ['you', 'yous'],
    'who + s' => ['who', 'whos'],
    'for + s' => ['for', 'fors'],
    'and + ed' => ['and', 'anded'],
]);

it('knows the forms of a function word that the list itself has', function (string $word): void {
    expect(EnglishLexicon::isWord($word))->toBeTrue();
})->with(['ones', 'hers', 'ours', 'yours', 'theirs', 'its', 'others', 'themselves', 'thanks']);

// ---------------------------------------------------------------- the rules of spelling

it('turns a regular form back into its lemma by the rules of English spelling', function (string $form, string $lemma): void {
    expect(EnglishLexicon::lemmas($form))->toContain($lemma)
        ->and(EnglishLexicon::isWord($form))->toBeTrue();
})->with([
    'possessive' => ["mother's", 'mother'],
    'plural -s' => ['cats', 'cat'],
    'third person -s' => ['runs', 'run'],
    '-es after s' => ['buses', 'bus'],
    '-ses of a short word' => ['busses', 'bus'],
    '-zzes of a short word' => ['quizzes', 'quiz'],
    '-es after x' => ['boxes', 'box'],
    '-es after z' => ['jazzes', 'jazz'],
    '-es after ch' => ['watches', 'watch'],
    '-es after sh' => ['wishes', 'wish'],
    '-es after o' => ['goes', 'go'],
    '-es after o (does)' => ['does', 'do'],
    '-es after o (tomatoes)' => ['tomatoes', 'tomato'],
    '-es or -s after o' => ['mosquitos', 'mosquito'],
    '-s after o' => ['photos', 'photo'],
    '-s after vowel + o' => ['zoos', 'zoo'],
    '-ies' => ['studies', 'study'],
    '-ies of a noun' => ['cities', 'city'],
    '-s after vowel + y' => ['plays', 'play'],
    '-ed' => ['walked', 'walk'],
    '-d after e' => ['liked', 'like'],
    '-d after ee' => ['agreed', 'agree'],
    'a doubled consonant + ed' => ['stopped', 'stop'],
    'a doubled consonant + ed (planned)' => ['planned', 'plan'],
    'a doubled z + ed' => ['quizzed', 'quiz'],
    'a doubled consonant of the stressed end' => ['preferred', 'prefer'],
    'no doubling of the other long words' => ['visited', 'visit'],
    'l doubled or not (travelled)' => ['travelled', 'travel'],
    'l doubled or not (traveled)' => ['traveled', 'travel'],
    'a k after c' => ['panicked', 'panic'],
    '-ied' => ['tried', 'try'],
    '-ied (studied)' => ['studied', 'study'],
    '-ed after vowel + y' => ['played', 'play'],
    '-ing' => ['walking', 'walk'],
    '-ing + e' => ['making', 'make'],
    '-ing + e (hoping)' => ['hoping', 'hope'],
    'e kept after ee' => ['seeing', 'see'],
    'e kept after ye' => ['dyeing', 'dye'],
    'a doubled consonant + ing' => ['running', 'run'],
    'a doubled consonant + ing (sitting)' => ['sitting', 'sit'],
    'a doubled consonant of the stressed end (beginning)' => ['beginning', 'begin'],
    'no doubling of the other long words (listening)' => ['listening', 'listen'],
    'y stays before ing' => ['studying', 'study'],
    '-ying' => ['lying', 'lie'],
    '-ying (dying)' => ['dying', 'die'],
    'aging and ageing' => ['ageing', 'age'],
    '-er' => ['taller', 'tall'],
    '-r after e' => ['nicer', 'nice'],
    'a doubled consonant + er' => ['bigger', 'big'],
    '-ier' => ['happier', 'happy'],
    '-ier or -yer of a short word' => ['dryer', 'dry'],
    '-est' => ['tallest', 'tall'],
    '-st after e' => ['nicest', 'nice'],
    'a doubled consonant + est' => ['biggest', 'big'],
    'a doubled consonant + est (hottest)' => ['hottest', 'hot'],
    '-iest' => ['happiest', 'happy'],
    '-ly' => ['quickly', 'quick'],
    '-ly after e' => ['nicely', 'nice'],
    '-ily' => ['happily', 'happy'],
    '-ly after consonant + le' => ['gently', 'gentle'],
    '-lly' => ['fully', 'full'],
    '-ically' => ['basically', 'basic'],
    '-uly' => ['truly', 'true'],
]);

it('gives the lemma a form was made from, and no stem of a misspelt form', function (): void {
    expect(EnglishLexicon::lemmas('making'))->toBe(['make'])
        ->and(EnglishLexicon::lemmas('stopped'))->toContain('stop')
        ->and(EnglishLexicon::lemmas('stoped'))->not->toContain('stop')      // `stope` + d would spell it, and nobody has `stope`
        ->and(EnglishLexicon::lemmas('makeing'))->toBe([])
        ->and(EnglishLexicon::lemmas('studys'))->toBe([])
        ->and(EnglishLexicon::lemmas('happyer'))->not->toContain('happy');
});

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

it('takes a silent e off before -ing, -ed, -er and -est: the form is spelled for its lemma', function (string $form, string $notForm): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue()
        ->and(EnglishLexicon::isWord($notForm))->toBeFalse();
})->with([
    'make + ing' => ['making', 'makeing'],
    'write + ing' => ['writing', 'writeing'],
    'come + ing' => ['coming', 'comeing'],
    'have + ing' => ['having', 'haveing'],
    'give + ing' => ['giving', 'giveing'],
    'live + ing' => ['living', 'liveing'],
    'take + ing' => ['taking', 'takeing'],
    'use + ing' => ['using', 'useing'],
    'dance + ing' => ['dancing', 'danceing'],
    'drive + ing' => ['driving', 'driveing'],
    'bake + ed' => ['baked', 'bakeed'],
    'like + ed' => ['liked', 'likeed'],
    'nice + er' => ['nicer', 'niceer'],
    'nice + est' => ['nicest', 'niceest'],
    'large + er' => ['larger', 'largeer'],
    'late + er' => ['later', 'lateer'],
]);

it('keeps the e after ee, oe, ye and turns ie into y: seeing, dyeing, lying, dying', function (string $word): void {
    expect(EnglishLexicon::isWord($word))->toBeTrue();
})->with(['seeing', 'agreeing', 'freeing', 'fleeing', 'dyeing', 'eyeing', 'eying', 'shoeing', 'canoeing', 'lying', 'dying', 'tying', 'aging', 'ageing', 'queuing', 'queueing']);

it('doubles the last consonant of a short word and of a long one stressed on the last syllable, and no other', function (string $form, string $notForm): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue()
        ->and(EnglishLexicon::isWord($notForm))->toBeFalse();
})->with([
    'stop + ed' => ['stopped', 'stoped'],
    'stop + ing' => ['stopping', 'stoping'],
    'run + ing' => ['running', 'runing'],
    'swim + ing' => ['swimming', 'swiming'],
    'get + ing' => ['getting', 'geting'],
    'big + er' => ['bigger', 'biger'],
    'big + est' => ['biggest', 'bigest'],
    'hot + er' => ['hotter', 'hoter'],
    'hot + est' => ['hottest', 'hotest'],
    'shop + ing' => ['shopping', 'shoping'],
    'drop + ed' => ['dropped', 'droped'],
    'chat + ing' => ['chatting', 'chating'],
    'put + ing' => ['putting', 'puting'],
    'quiz + ed' => ['quizzed', 'quized'],
    'begin + ing (stress on the last syllable)' => ['beginning', 'begining'],
    'occur + ed' => ['occurred', 'occured'],
    'prefer + ed' => ['preferred', 'prefered'],
    'control + ed' => ['controlled', 'controled'],
    'commit + ed' => ['committed', 'commited'],
    'admit + ed' => ['admitted', 'admited'],
    'forget + ing' => ['forgetting', 'forgeting'],
    'regret + ed' => ['regretted', 'regreted'],
    'submit + ed' => ['submitted', 'submited'],
    'transfer + ed' => ['transferred', 'transfered'],
    'equip + ed' => ['equipped', 'equiped'],
    'visit + ed (stress on the first)' => ['visited', 'visitted'],
    'open + ed' => ['opened', 'openned'],
    'happen + ed' => ['happened', 'happenned'],
    'listen + ing' => ['listening', 'listenning'],
    'offer + ed' => ['offered', 'offerred'],
    'develop + ed' => ['developed', 'developped'],
    'limit + ed' => ['limited', 'limitted'],
    'enter + ed' => ['entered', 'enterred'],
    'answer + ed' => ['answered', 'answerred'],
    'meet + ing (two vowels: no doubling)' => ['meeting', 'meetting'],
    'eat + ing' => ['eating', 'eatting'],
    'look + ing' => ['looking', 'lookking'],
    'fix + ed (x is never doubled)' => ['fixed', 'fixxed'],
    'show + ed (w is never doubled)' => ['showed', 'showwed'],
    'play + ed (y is never doubled)' => ['played', 'playyed'],
]);

it('lets a final l be doubled or not, in either spelling, and only an l', function (string $form): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue();
})->with(['travelled', 'traveled', 'travelling', 'traveling', 'traveller', 'traveler', 'cancelled', 'canceled', 'labelled', 'labeled', 'modelled', 'modeled', 'levelled', 'leveled']);

it('turns consonant + y into i before the ending and leaves vowel + y alone', function (string $form, string $notForm): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue()
        ->and(EnglishLexicon::isWord($notForm))->toBeFalse();
})->with([
    'study + s' => ['studies', 'studys'],
    'study + ed' => ['studied', 'studyed'],
    'study + ing keeps the y' => ['studying', 'studiing'],
    'family + s' => ['families', 'familys'],
    'city + s' => ['cities', 'citys'],
    'baby + s' => ['babies', 'babys'],
    'carry + s' => ['carries', 'carrys'],
    'carry + ed' => ['carried', 'carryed'],
    'try + ed' => ['tried', 'tryed'],
    'happy + er' => ['happier', 'happyer'],
    'happy + est' => ['happiest', 'happyest'],
    'happy + ly' => ['happily', 'happyly'],
    'easy + er' => ['easier', 'easyer'],
    'play + s (vowel + y)' => ['plays', 'plaies'],
    'play + ed' => ['played', 'plaied'],
    'stay + ed' => ['stayed', 'staied'],
    'enjoy + ed' => ['enjoyed', 'enjoied'],
    'boy + s' => ['boys', 'boies'],
    'key + s' => ['keys', 'kies'],
    'buy + er' => ['buyer', 'buier'],
]);

it('puts es only after s, x, z, ch, sh and after a short list of words in o', function (string $form, string $notForm): void {
    expect(EnglishLexicon::isWord($form))->toBeTrue()
        ->and(EnglishLexicon::isWord($notForm))->toBeFalse();
})->with([
    'box' => ['boxes', 'boxs'],
    'watch' => ['watches', 'watchs'],
    'wish' => ['wishes', 'wishs'],
    'kiss' => ['kisses', 'kisss'],
    'class' => ['classes', 'classs'],
    'go' => ['goes', 'gos'],
    'do' => ['does', 'dos'],
    'tomato' => ['tomatoes', 'tomatos'],
    'potato' => ['potatoes', 'potatos'],
    'hero' => ['heroes', 'heros'],
    'photo takes s only' => ['photos', 'photoes'],
    'piano takes s only' => ['pianos', 'pianoes'],
    'zoo takes s only' => ['zoos', 'zooes'],
    'video takes s only' => ['videos', 'videoes'],
    'cat takes s only' => ['cats', 'cates'],
]);

it('knows a possessive of any word of the lists, the irregular plural too', function (): void {
    foreach (["boy's", "mother's", "teacher's", "women's", "children's", "men's", "people's", "dog's"] as $word) {
        expect(EnglishLexicon::isWord($word))->toBeTrue("«{$word}»");
    }
    expect(EnglishLexicon::isWord("xqzvk's"))->toBeFalse()
        ->and(EnglishLexicon::isWord("'s"))->toBeFalse();
});

it('knows the forms the spelling gives: stopped, running, bigger, hottest, making, baked, nicer, studies, studied, happier, plays, played, visited, opened, happened, listening, travelled, traveled, beginning, preferred, goes, tomatoes, boy\'s', function (string $word): void {
    expect(EnglishLexicon::isWord($word))->toBeTrue();
})->with(['stopped', 'running', 'bigger', 'hottest', 'making', 'baked', 'nicer', 'studies', 'studied', 'happier', 'plays', 'played', 'visited', 'opened', 'happened',
    'listening', 'travelled', 'traveled', 'beginning', 'preferred', 'goes', 'tomatoes', "boy's"]);

it('does not know a misspelt form: stoped, runing, biger, makeing, studys, familys, happyer, studyed, meetting, visitted, openned', function (string $word): void {
    expect(EnglishLexicon::isWord($word))->toBeFalse();
})->with(['stoped', 'runing', 'biger', 'makeing', 'studys', 'familys', 'happyer', 'studyed', 'meetting', 'visitted', 'openned']);

it('errs on the strict side: a form that is spelled right for SOME lemma is a word, whatever part of speech the lemma is', function (): void {
    // The rules know nothing of the part of speech: site + ing, plane + ed, the American spelling, a regular form of an irregular verb.
    expect(EnglishLexicon::isWord('siting'))->toBeTrue()
        ->and(EnglishLexicon::isWord('planed'))->toBeTrue()
        ->and(EnglishLexicon::isWord('traveling'))->toBeTrue()
        ->and(EnglishLexicon::isWord('goed'))->toBeTrue()
        ->and(EnglishLexicon::isWord('hoping'))->toBeTrue()
        ->and(EnglishLexicon::isWord('hopping'))->toBeTrue();
});

it('gives an e-ending noun or adjective no -ing of its own: `cuting` is not `cute` + `ing`, while `hoping` is `hope` + `ing`', function (string $slip, string $word): void {
    expect(EnglishLexicon::isWord($slip))->toBeFalse()
        ->and(EnglishLexicon::isWord($word))->toBeTrue();
})->with([
    'cut / cute' => ['cuting', 'cutting'],
    'hug / huge' => ['huging', 'hugging'],
    'nod / node' => ['noding', 'nodding'],
    'spin / spine' => ['spining', 'spinning'],
    'cap / cape' => ['caping', 'capping'],
    'rot / rote' => ['roting', 'rotting'],
    'pan / pane' => ['paning', 'panning'],
    'war / ware' => ['waring', 'warring'],
]);

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

/**
 * @return list<string> the slips of an ending (docs/16 §5.2): the doubling left out or put in, the silent e kept, the wrong y; the 40 of the
 *                      review minus the four that are words (`siting`, `planed`, `traveling`: see below) and the three that are two edits away
 */
function lexiconSuffixSlips(): array
{
    return ['stoped', 'runing', 'begining', 'swiming', 'geting', 'biger', 'hoter', 'shoping', 'occured', 'prefered', 'puting', 'cuting', 'droped', 'chating',
        'stoping', 'bigest', 'hotest', 'makeing', 'writeing', 'comeing', 'haveing', 'giveing', 'liveing', 'takeing', 'useing', 'danceing', 'driveing',
        'meetting', 'eatting', 'visitted', 'openned', 'lookking', 'studys', 'familys', 'citys', 'plaied', 'happyer', 'studyed', 'visitting', 'openning'];
}

it('does not take a slip of the pen for a word', function (string $slip): void {
    expect(EnglishLexicon::isWord($slip))->toBeFalse();
})->with(array_map(static fn (string $slip): array => [$slip], [...lexiconSlips(), ...lexiconSuffixSlips()]));

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

it('answers a thousand questions about words that need every rule in less than 50 ms', function (): void {
    EnglishLexicon::isWord('warm');

    $tokens = ['stoped', 'makeing', 'studys', 'visitted', 'openned', 'happyer', 'meting', 'ther', 'runing', 'cuting'];
    $start = hrtime(true);
    $found = 0;
    for ($i = 0; $i < 1000; $i++) {
        $found += EnglishLexicon::isWord($tokens[$i % 10]) ? 1 : 0;
    }
    $milliseconds = (hrtime(true) - $start) / 1_000_000;

    expect($found)->toBe(0)
        ->and($milliseconds)->toBeLessThan(50.0);
});
