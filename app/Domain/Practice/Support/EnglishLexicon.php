<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use RuntimeException;

/**
 * Is this token a known English word? A pure function of the token and of two text files (no database, no network):
 *   - `resources/lexicon/en-common.txt` - the LEMMAS and the ordinary words of the levels A1-B2 (function words, numerals, days and
 *     months, everyday nouns, verbs, adjectives, adverbs): the only words a rule of spelling may start from;
 *   - `resources/lexicon/en-irregular.txt` - the irregular forms: the past and the participle of the irregular verbs, the irregular
 *     plurals and degrees of comparison. They are words, but never a base: `met` is a word, `meting` is not `met` + `ing`.
 * One lower-case word to a line, `#` starts a comment line. The files are read once per process (a static memo).
 *
 * It exists for the dictation (docs/15 §3.4, docs/16 §5.2): a slip of the pen may be forgiven only when it spells NOTHING - `becuase`
 * for `because` is a typo, but `light` for `right`, `were` for `where`, `wrote` for `write` or `women` for `woman` is another word, a
 * mistake the review must name as one. So the grader asks the lexicon about the word that was TYPED.
 *
 * A token is a word when it is in either file, or when it is a CORRECTLY SPELLED regular form of a lemma of the first one
 * ({@see lemmas()}): the spelling of the form is worked out from the lemma, so a misspelt form is no word (`stoped`, `makeing`, `studys`).
 * For a lemma `L`:
 *   - `-ed`: a silent `e` takes `d` (`like` -> `liked`); `consonant + y` -> `ied` (`study` -> `studied`, but `play` -> `played`); a
 *     one-syllable `consonant-vowel-consonant` lemma (not ending in w, x, y) doubles the last letter (`stop` -> `stopped`), and so does a
 *     longer one stressed on the last syllable (a short list: `begin`, `occur`, `prefer`...); a longer one with a final `l` takes both
 *     (`travelled` / `traveled`); any other longer one does not (`visited`, `opened`, `happened`); `-ic` takes `k` (`panicked`);
 *   - `-ing`: the silent `e` is dropped (`make` -> `making`) but stays after `ee`, `oe`, `ye` (`seeing`); `ie` -> `ying` (`lying`); doubling
 *     as above (`running`, `beginning`, `listening`); `y` stays (`studying`);
 *   - `-er` / `-est`: `e` + `r` / `st` (`nicer`); `consonant + y` -> `ier` / `iest` (`happier`); doubling as above (`bigger`, `hottest`);
 *   - `-s`: `es` after s, x, z, ch, sh (`boxes`, `watches`) and after a short list of words in `o` (`goes`, `does`, `tomatoes`, `heroes`),
 *     `consonant + y` -> `ies` (`studies`), otherwise `s` (`plays`, `photos`);
 *   - `-ly`: `consonant + y` -> `ily`, `consonant + le` -> `ly`, `-ll` -> `lly`, `-ic` -> `ically`, otherwise `ly`;
 *   - `'s` (possessive) after any word of either file.
 * A form of a form is not a form (one rule at a time), a stem is at least two letters long, and a function word (`the`, `one`, `again`,
 * {@see NO_ENDINGS}) takes no ending: `ther` is not `the` + `r`.
 *
 * The check errs on the STRICT side by design, and the rules know nothing of the part of speech: a noun + `er` that looks like a form
 * (`siter` = `site` + `r`), a verb whose twin is a word (`siting` = `site` + `ing`, `planed`, `hoping`), the other spelling (`traveling`), a
 * regular form of an irregular verb (`goed`) are all taken for words. That only turns a `typo` into a `wrong`. A word that the files
 * lack leaves the slip forgiven, as it was before the lexicon.
 */
final class EnglishLexicon
{
    /** The lemmas and the ordinary words, relative to `resources/`. */
    public const FILE = 'lexicon/en-common.txt';

    /** The irregular forms (words, but never the base of a rule), relative to `resources/`. */
    public const IRREGULAR_FILE = 'lexicon/en-irregular.txt';

    /** The shortest stem a rule may leave: the word `is` is not `i` + `s`, `ed` is not `` + `ed`. */
    private const MIN_STEM = 2;

    /**
     * Longer lemmas that end in consonant-vowel-consonant AND are stressed on the last syllable: the last letter is doubled
     * (`begin` -> `beginning`, `occur` -> `occurred`, `control` -> `controlled`). The others (`visit`, `open`, `listen`) are not.
     */
    private const STRESSED_LAST = [
        'begin', 'occur', 'prefer', 'refer', 'admit', 'permit', 'forget', 'regret', 'control', 'commit', 'omit', 'upset', 'submit',
        'transfer', 'equip', 'deter', 'compel', 'expel', 'propel', 'rebel', 'patrol', 'excel',
        'forbid', 'transmit', 'remit', 'emit', 'acquit', 'outwit', 'defer', 'infer', 'confer', 'incur', 'recur', 'concur', 'repel', 'dispel',
        'fulfil', 'instil', 'distil', 'enrol', 'reset', 'offset', 'unplug', 'unwrap', 'unzip', 'kidnap', 'handicap', 'program',
    ];

    /** Lemmas in `o` that take `es` in the plural / the third person. */
    private const ES_AFTER_O = ['go', 'do', 'undo', 'redo', 'undergo', 'forgo', 'outdo', 'overdo', 'tomato', 'potato', 'hero', 'echo', 'veto', 'torpedo', 'embargo'];

    /** Lemmas in `o` that take `es` or `s` (`mosquitoes` / `mosquitos`). */
    private const ES_OR_S_AFTER_O = ['mosquito', 'volcano', 'zero', 'mango', 'tornado', 'cargo', 'buffalo', 'domino', 'motto', 'halo'];

    /** Lemmas in `e` whose `-ing` form is spelled both ways (`aging` / `ageing`, `queuing` / `queueing`, `eying` / `eyeing`). */
    private const E_EITHER = ['age', 'queue', 'glue', 'cue', 'clue', 'blue', 'eye'];

    /**
     * Nouns and adjectives in `e` that are no verbs but have a one-syllable verb for a twin (`cut` / `cute`, `nod` / `node`, `spin` /
     * `spine`): no `-ing` form, or the slip `cuting` for `cutting` would be taken for `cute` + `ing`. (Twins that ARE verbs, `hop` / `hope`,
     * `sit` / `site`, `plan` / `plane`, keep both forms: `hoping` is a word.)
     */
    private const NOT_VERBS = ['cape', 'cute', 'fate', 'huge', 'node', 'pane', 'rote', 'spine', 'ware'];

    /**
     * Words that take no ending (articles, pronouns, prepositions, conjunctions, modals, adverbs of degree, interjections): never the
     * base of a rule, so `ther` is not `the` + `r`, `onest` is not `one` + `st`, `againest` is not `again` + `est`. Their own forms
     * (`ones`, `hers`, `its`, `others`) are in the list of words.
     */
    private const NO_ENDINGS = [
        'a', 'an', 'the', 'this', 'that', 'these', 'those', 'some', 'any', 'every', 'each', 'all', 'both', 'either', 'neither', 'such', 'much', 'many',
        'few', 'several', 'another', 'enough', 'no', 'one', 'none',
        'i', 'me', 'my', 'mine', 'we', 'us', 'our', 'ours', 'you', 'your', 'yours', 'he', 'him', 'his', 'she', 'her', 'hers', 'it', 'its', 'they', 'them',
        'their', 'theirs', 'who', 'whom', 'whose', 'what', 'which', 'myself', 'yourself', 'himself', 'herself', 'itself', 'ourselves', 'themselves',
        'yourselves',
        'about', 'above', 'across', 'after', 'against', 'along', 'among', 'around', 'at', 'before', 'behind', 'below', 'beside', 'besides', 'between',
        'beyond', 'by', 'during', 'except', 'for', 'from', 'in', 'inside', 'into', 'of', 'off', 'on', 'onto', 'outside', 'per', 'since', 'through',
        'throughout', 'to', 'toward', 'towards', 'under', 'until', 'up', 'upon', 'via', 'with', 'within', 'without',
        'and', 'or', 'but', 'so', 'if', 'as', 'because', 'although', 'though', 'unless', 'while', 'whether', 'nor', 'than',
        'when', 'where', 'why', 'how', 'here', 'there', 'then', 'thus', 'now', 'ever', 'never', 'always', 'often', 'also', 'too', 'very', 'quite',
        'rather', 'again', 'ago', 'away', 'already', 'almost', 'only', 'yet', 'perhaps', 'maybe', 'however', 'whatever', 'whenever', 'wherever',
        'whichever', 'whoever', 'far',
        'be', 'could', 'may', 'might', 'must', 'shall', 'should', 'would', 'ought',
        'ah', 'hi', 'hey', 'hmm', 'oh', 'ok', 'okay', 'ouch', 'oops', 'ow', 'ugh', 'wow', 'yeah', 'yep', 'yes', 'nope', 'bye', 'goodbye',
        'dr', 'mr', 'mrs', 'ms', 'pe',
    ];

    /** @var array{lemmas: array<string, int>, all: array<string, int>}|null the words of the files as keys */
    private static ?array $lists = null;

    /** Is the token (any case; the typographic apostrophe counts as the plain one) a word of the lists or a correctly spelled regular form of one? */
    public static function isWord(string $token): bool
    {
        $token = self::clean($token);
        if ($token === '') {
            return false;
        }

        $lists = self::lists();
        if (isset($lists['all'][$token])) {
            return true;
        }

        foreach (self::analyses($token) as [$stem, $suffix]) {
            // The possessive may follow any word, `women's`; every other rule starts from a lemma only.
            if (isset($lists[$suffix === "'s" ? 'all' : 'lemmas'][$stem])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stems the token is a correctly spelled regular form of: what is left when one suffix rule is undone AND the spelling the
     * rule gives for that stem is the token's own. Candidates only - they need not be words (`stopped` -> `stop`, and `stopp`, which
     * no list has); {@see isWord()} keeps the ones the list of lemmas knows.
     *
     * @return list<string> without repeats, none shorter than two letters
     */
    public static function lemmas(string $token): array
    {
        $stems = [];
        foreach (self::analyses(self::clean($token)) as [$stem]) {
            $stems[$stem] = true;
        }

        return array_map('strval', array_keys($stems));
    }

    /**
     * Every (stem, suffix) the token is a correctly spelled form of. The stems are tried in all the shapes a rule may have left
     * (`happi` -> `happy`, `stopp` -> `stop`, `mak` -> `make`), and each one is kept only when the rule, run FORWARD on it, gives the token.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function analyses(string $token): array
    {
        /** @var array<string, array{0: string, 1: string}> $found */
        $found = [];
        $add = static function (string $stem, string $suffix) use (&$found, $token): void {
            if (strlen($stem) < self::MIN_STEM || ($suffix !== "'s" && in_array($stem, self::NO_ENDINGS, true))) {
                return;
            }
            if (in_array($token, self::forms($stem, $suffix), true)) {
                $found[$suffix.'|'.$stem] = [$stem, $suffix];
            }
        };

        if (str_ends_with($token, "'s")) {
            $add(substr($token, 0, -2), "'s");
        }

        foreach (['s' => 1, 'es' => 2, 'ed' => 2, 'ing' => 3, 'er' => 2, 'est' => 3, 'ly' => 2] as $ending => $length) {
            if (! str_ends_with($token, $ending)) {
                continue;
            }
            $suffix = match ($ending) {
                'es' => 's',
                default => $ending,
            };
            foreach (self::shapes(substr($token, 0, -$length)) as $stem) {
                $add($stem, $suffix);
            }
        }

        return array_values($found);
    }

    /**
     * What the stem of a form may have looked like before the suffix was put on: as it is, with the silent `e` back (`mak` -> `make`),
     * `le` back (`gent` -> `gentle`), `i` -> `y` (`happi` -> `happy`), `y` -> `ie` (`ly` -> `lie`), without the doubled last letter
     * (`stopp` -> `stop`), `ck` -> `c` (`panick` -> `panic`), `l` -> `ll` (`ful` -> `full`), `ical` -> `ic` (`basical` -> `basic`).
     *
     * @return list<string>
     */
    private static function shapes(string $stem): array
    {
        if ($stem === '') {
            return [];
        }

        $shapes = [$stem, $stem.'e', $stem.'le'];
        if (str_ends_with($stem, 'i')) {
            $shapes[] = substr($stem, 0, -1).'y';
        }
        if (str_ends_with($stem, 'y')) {
            $shapes[] = substr($stem, 0, -1).'ie';
        }
        if (preg_match('/([bcdfghjklmnpqrstvwxz])\1$/', $stem) === 1) {
            $shapes[] = substr($stem, 0, -1);
        }
        if (str_ends_with($stem, 'ck')) {
            $shapes[] = substr($stem, 0, -1);
        }
        if (str_ends_with($stem, 'l')) {
            $shapes[] = $stem.'l';
        }
        if (str_ends_with($stem, 'ical')) {
            $shapes[] = substr($stem, 0, -2);
        }

        return $shapes;
    }

    /**
     * The spellings the rule of `$suffix` gives for the lemma: one, or two where English has both (`travelled` / `traveled`). Empty
     * for a stem that is not made of the letters a-z (no rule is tried on `o'clock` or `café`) and for a suffix the stem cannot take.
     *
     * @return list<string>
     */
    private static function forms(string $lemma, string $suffix): array
    {
        if ($suffix === "'s") {
            return [$lemma."'s"];
        }
        if (preg_match('/\A[a-z]+\z/', $lemma) !== 1) {
            return [];
        }

        $consonantY = preg_match('/[^aeiou]y\z/', $lemma) === 1;
        $endsE = str_ends_with($lemma, 'e');

        return match ($suffix) {
            's' => self::pluralForms($lemma, $consonantY),
            'ed' => $endsE ? [$lemma.'d'] : ($consonantY ? [substr($lemma, 0, -1).'ied'] : self::vowelSuffixed($lemma, 'ed')),
            'ing' => $endsE ? self::ingFormsOfE($lemma) : self::vowelSuffixed($lemma, 'ing'),
            'er', 'est' => match (true) {
                $endsE => [$lemma.substr($suffix, 1)],
                $consonantY => self::syllables($lemma) === 1
                    ? [substr($lemma, 0, -1).'i'.$suffix, $lemma.$suffix]   // dry -> drier, dryer
                    : [substr($lemma, 0, -1).'i'.$suffix],
                default => self::vowelSuffixed($lemma, $suffix),
            },
            'ly' => self::lyForms($lemma, $consonantY),
            default => [],
        };
    }

    /** @return list<string> */
    private static function pluralForms(string $lemma, bool $consonantY): array
    {
        if (preg_match('/(?:s|x|z|ch|sh)\z/', $lemma) === 1) {
            $last = $lemma[-1];
            // bus -> buses, busses; quiz -> quizzes: a short word doubles the s / z.
            if (($last === 's' || $last === 'z') && self::endsConsonantVowelConsonant($lemma) && self::syllables($lemma) === 1) {
                return $last === 'z' ? [$lemma.'zes'] : [$lemma.'es', $lemma.'ses'];
            }

            return [$lemma.'es'];
        }
        if ($consonantY) {
            return [substr($lemma, 0, -1).'ies'];
        }
        if (str_ends_with($lemma, 'o')) {
            return match (true) {
                in_array($lemma, self::ES_AFTER_O, true) => [$lemma.'es'],
                in_array($lemma, self::ES_OR_S_AFTER_O, true) => [$lemma.'es', $lemma.'s'],
                default => [$lemma.'s'],
            };
        }

        return [$lemma.'s'];
    }

    /** @return list<string> */
    private static function ingFormsOfE(string $lemma): array
    {
        if (in_array($lemma, self::NOT_VERBS, true)) {
            return [];
        }
        if (str_ends_with($lemma, 'ie')) {
            return [substr($lemma, 0, -2).'ying'];                     // lie -> lying
        }

        $dropped = substr($lemma, 0, -1).'ing';                         // make -> making
        $kept = $lemma.'ing';                                           // see -> seeing
        if (in_array($lemma, self::E_EITHER, true)) {
            return [$dropped, $kept];
        }

        return preg_match('/(?:ee|oe|ye)\z/', $lemma) === 1 ? [$kept] : [$dropped];
    }

    /**
     * `-ed`, `-ing`, `-er`, `-est` after a lemma that does not end in `e` or in consonant + `y`: the last consonant is doubled
     * (`stop` -> `stopped`, `begin` -> `beginning`), or may be (`travelled` / `traveled`), `-ic` takes a `k`, otherwise nothing changes.
     *
     * @return list<string>
     */
    private static function vowelSuffixed(string $lemma, string $suffix): array
    {
        if (self::endsConsonantVowelConsonant($lemma)) {
            $doubled = $lemma.$lemma[-1].$suffix;
            if (self::syllables($lemma) === 1 || in_array($lemma, self::STRESSED_LAST, true)) {
                return [$doubled];
            }
            if (str_ends_with($lemma, 'l')) {
                return [$lemma.$suffix, $doubled];
            }
        }
        if (str_ends_with($lemma, 'ic')) {
            return [$lemma.'k'.$suffix];                                // panic -> panicked, panicking
        }

        return [$lemma.$suffix];
    }

    /** @return list<string> */
    private static function lyForms(string $lemma, bool $consonantY): array
    {
        return match (true) {
            $consonantY => self::syllables($lemma) === 1
                ? [substr($lemma, 0, -1).'ily', $lemma.'ly']            // dry -> drily, dryly
                : [substr($lemma, 0, -1).'ily'],
            preg_match('/[aeiou]y\z/', $lemma) === 1 => $lemma === 'coy' ? ['coyly'] : [],
            preg_match('/[^aeiou]le\z/', $lemma) === 1 => [substr($lemma, 0, -1).'y'],   // gentle -> gently
            str_ends_with($lemma, 'll') => [$lemma.'y'],                                 // full -> fully, dull -> dully
            str_ends_with($lemma, 'ue') => [substr($lemma, 0, -1).'ly'],                 // true -> truly
            str_ends_with($lemma, 'ic') => [$lemma.'ally'],                              // basic -> basically
            default => [$lemma.'ly'],
        };
    }

    /** Consonant, vowel, consonant (not w, x, y) at the end: the shape that doubles its last letter. A `qu` counts as one consonant (`quit`, `equip`). */
    private static function endsConsonantVowelConsonant(string $word): bool
    {
        return preg_match('/[^aeiou][aeiou][^aeiouwxy]\z/', str_replace('qu', 'q', $word)) === 1;
    }

    /** The number of vowel groups (`y` counts: `system` has two, `crystal` two, `yes` one). */
    private static function syllables(string $word): int
    {
        return (int) preg_match_all('/[aeiouy]+/', str_replace('qu', 'q', $word));
    }

    /** The token in lower case, with the typographic apostrophes made plain. */
    private static function clean(string $token): string
    {
        return strtr(mb_strtolower(trim($token)), ["\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'", "\u{00B4}" => "'"]);
    }

    /** @return array{lemmas: array<string, int>, all: array<string, int>} */
    private static function lists(): array
    {
        if (self::$lists !== null) {
            return self::$lists;
        }

        $lemmas = array_flip(self::read(self::FILE));

        return self::$lists = ['lemmas' => $lemmas, 'all' => $lemmas + array_flip(self::read(self::IRREGULAR_FILE))];
    }

    /** @return list<string> the words of one file; the comment lines and the blank ones are not words */
    private static function read(string $file): array
    {
        $path = resource_path($file);
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false) {
            throw new RuntimeException("The word list {$path} cannot be read.");
        }

        $words = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $words[] = $line;
            }
        }

        return $words;
    }
}
