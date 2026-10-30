<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use RuntimeException;

/**
 * Is this token a known English word form? A pure function of the token and of one text file (no database, no network):
 * `resources/lexicon/en-common.txt` - the common lemmas of the levels A1-B2, ALL the forms of the irregular verbs, the irregular
 * plurals and the irregular degrees of comparison, one lower-case word to a line, `#` starts a comment line. The file is read once
 * per process (a static memo).
 *
 * It exists for the dictation (docs/15 §3.4, docs/16 §5.2): a slip of the pen may be forgiven only when it spells NOTHING - `becuase`
 * for `because` is a typo, but `light` for `right`, `were` for `where`, `wrote` for `write` or `women` for `woman` is another word, a
 * mistake the review must name as one. So the grader asks the lexicon about the word that was TYPED.
 *
 * A form is known when it is in the file, or when one rule of English spelling turns it into a lemma that is ({@see lemmas()}):
 *   - `'s` (possessive);
 *   - `-s`, `-es` after s, x, z, ch, sh, o, `-ies` -> `y`;
 *   - `-ed`, `-d` after e, a doubled consonant + `ed`, `-ied` -> `y`;
 *   - `-ing`, `-ing` + `e`, a doubled consonant + `ing`, `-ying` -> `ie`;
 *   - `-er` / `-est`, `-r` / `-st` after e, a doubled consonant + `er` / `est`, `-ier` / `-iest` -> `y`;
 *   - `-ly`, `-ily` -> `y`.
 * The rules are applied once (a form of a form is not a form), and a stem is at least two letters long.
 *
 * Which way the list errs is by design. A word that the list or the rules take for English although it is not (`begining` = `begin`
 * + `ing`) only makes the dictation STRICTER: `wrong` where `typo` could have been. A word the list lacks leaves the old behaviour:
 * the slip is forgiven, as it was before the lexicon. So the list is never "complete", and nothing breaks when it is not.
 */
final class EnglishLexicon
{
    /** The word list, relative to `resources/`. */
    public const FILE = 'lexicon/en-common.txt';

    /** The shortest stem a rule may leave: the word `is` is not `i` + `s`, `ed` is not `` + `ed`. */
    private const MIN_STEM = 2;

    /** A consonant that English doubles before a suffix (`stop` -> `stopped`, `big` -> `bigger`). */
    private const DOUBLED = '/([bcdfghjklmnpqrstvwxz])\1$/';

    /** @var array<string, int>|null the words of the file as keys */
    private static ?array $words = null;

    /** Is the token (any case; the typographic apostrophe counts as the plain one) a word of the list or a regular form of one? */
    public static function isWord(string $token): bool
    {
        $token = self::clean($token);
        if ($token === '') {
            return false;
        }

        $words = self::words();
        if (isset($words[$token])) {
            return true;
        }

        foreach (self::lemmas($token) as $lemma) {
            if (isset($words[$lemma])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lemmas the token may be a regular form of: what is left when one suffix rule is undone. Candidates only - most of them
     * are no words (`walked` -> `walk`, `walke`, `walk`); {@see isWord()} keeps the ones the list knows.
     *
     * @return list<string> without repeats, none shorter than two letters
     */
    public static function lemmas(string $token): array
    {
        $token = self::clean($token);
        /** @var list<string|null> $lemmas */
        $lemmas = [];

        // mother's -> mother
        if (str_ends_with($token, "'s")) {
            $lemmas[] = substr($token, 0, -2);
        }

        // cats, boxes, watches, goes, studies
        if (str_ends_with($token, 's')) {
            $lemmas[] = substr($token, 0, -1);
            if (str_ends_with($token, 'es') && preg_match('/(?:s|x|z|ch|sh|o)$/', substr($token, 0, -2)) === 1) {
                $lemmas[] = substr($token, 0, -2);
            }
            if (str_ends_with($token, 'ies')) {
                $lemmas[] = substr($token, 0, -3).'y';
            }
        }

        // walked, liked, agreed, stopped, studied
        if (str_ends_with($token, 'ed')) {
            $lemmas[] = substr($token, 0, -2);
            $lemmas[] = self::undoubled(substr($token, 0, -2));
            if (str_ends_with($token, 'ied')) {
                $lemmas[] = substr($token, 0, -3).'y';
            }
        }
        $lemmas[] = self::beforeE($token, 'd');

        // walking, making, running, lying
        if (str_ends_with($token, 'ing')) {
            $lemmas[] = substr($token, 0, -3);
            $lemmas[] = substr($token, 0, -3).'e';
            $lemmas[] = self::undoubled(substr($token, 0, -3));
            if (str_ends_with($token, 'ying')) {
                $lemmas[] = substr($token, 0, -4).'ie';
            }
        }

        // taller, nicer, bigger, happier
        if (str_ends_with($token, 'er')) {
            $lemmas[] = substr($token, 0, -2);
            $lemmas[] = self::undoubled(substr($token, 0, -2));
            if (str_ends_with($token, 'ier')) {
                $lemmas[] = substr($token, 0, -3).'y';
            }
        }
        $lemmas[] = self::beforeE($token, 'r');

        // tallest, nicest, biggest, happiest
        if (str_ends_with($token, 'est')) {
            $lemmas[] = substr($token, 0, -3);
            $lemmas[] = self::undoubled(substr($token, 0, -3));
            if (str_ends_with($token, 'iest')) {
                $lemmas[] = substr($token, 0, -4).'y';
            }
        }
        $lemmas[] = self::beforeE($token, 'st');

        // quickly, happily
        if (str_ends_with($token, 'ly')) {
            $lemmas[] = substr($token, 0, -2);
            if (str_ends_with($token, 'ily')) {
                $lemmas[] = substr($token, 0, -3).'y';
            }
        }

        $kept = [];
        foreach ($lemmas as $lemma) {
            if ($lemma !== null && strlen($lemma) >= self::MIN_STEM) {
                $kept[$lemma] = true;
            }
        }

        return array_map('strval', array_keys($kept));
    }

    /** The token in lower case, with the typographic apostrophes made plain. */
    private static function clean(string $token): string
    {
        return strtr(mb_strtolower(trim($token)), ["\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'", "\u{00B4}" => "'"]);
    }

    /** `bigg` -> `big`, `stopp` -> `stop`; null when the stem does not end in a doubled consonant. */
    private static function undoubled(string $stem): ?string
    {
        $single = preg_replace(self::DOUBLED, '$1', $stem, 1, $count);

        return $count === 1 ? $single : null;
    }

    /**
     * What is left when `$ending` is cut off a token whose stem ends in `e` (`liked` -> `like`, `nicer` -> `nice`, `nicest` -> `nice`):
     * the rule `-d` / `-r` / `-st` after an `e`. Null when the token does not end so, or its stem does not end in `e`.
     */
    private static function beforeE(string $token, string $ending): ?string
    {
        if (! str_ends_with($token, $ending)) {
            return null;
        }

        $stem = substr($token, 0, -strlen($ending));

        return str_ends_with($stem, 'e') ? $stem : null;
    }

    /** @return array<string, int> */
    private static function words(): array
    {
        if (self::$words !== null) {
            return self::$words;
        }

        $path = resource_path(self::FILE);
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

        return self::$words = array_flip($words);
    }
}
