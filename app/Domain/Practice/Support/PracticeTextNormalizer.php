<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use Normalizer;

/**
 * The one normalization of the texts the practice compares (docs/16 §5.1, §4.4): the answer of a dictation against
 * its accepted variants, a sentence assembled from fragments against the accepted sequences, the English key of a word.
 *
 * `normalize()` - in this order:
 *   1. the typographic apostrophes and the backtick become `'` (BEFORE NFKC, which would turn the acute accent `´` into
 *      "space + combining accent" and the apostrophe would be lost; docs/16 §5.1 lists NFKC first);
 *   2. NFKC (when the intl extension is there); the double quotes `" « » “ ” „` are deleted;
 *   3. the dashes and hyphens become a space ("well-known" = "well known");
 *   4. `. , ! ? ; : … ( ) [ ]` become a space;
 *   5. lower case;
 *   6. runs of white space become one space; the ends are trimmed.
 * An apostrophe inside a token stays (`don't`), digits stay. Contractions are NOT equated ("I'm" is not "I am"): only
 * the variants the author lists are.
 *
 * `SpellingGrader::normalize()` is not used: its rules for punctuation differ.
 */
final class PracticeTextNormalizer
{
    /** ’ ‘ ʼ ´ ` ′ */
    private const APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "\u{00B4}", '`', "\u{2032}"];

    /** " « » “ ” „ */
    private const DOUBLE_QUOTES = ['"', "\u{00AB}", "\u{00BB}", "\u{201C}", "\u{201D}", "\u{201E}"];

    /** the hyphen, the non-breaking hyphen (NFKC turns it into U+2010), the figure dash, the en and em dashes, the horizontal bar, `-` */
    private const DASHES = ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", '-'];

    /** . , ! ? ; : … ( ) [ ] */
    private const PUNCTUATION = ['.', ',', '!', '?', ';', ':', "\u{2026}", '(', ')', '[', ']'];

    public static function normalize(string $text): string
    {
        $text = self::nfkc(str_replace(self::APOSTROPHES, "'", $text));
        $text = str_replace(self::DOUBLE_QUOTES, '', $text);
        $text = str_replace([...self::DASHES, ...self::PUNCTUATION], ' ', $text);

        return self::squeeze(mb_strtolower($text));
    }

    /**
     * The tokens of the normalized text; an empty text has none.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * The key to compare English words of the pairs (docs/16 §4.4): NFKC, lower case, the apostrophes, one space.
     * Unlike {@see normalize()} it keeps the punctuation and the hyphens: "ice-cream" is not "ice cream" there.
     */
    public static function key(string $text): string
    {
        $text = mb_strtolower(self::nfkc(str_replace(self::APOSTROPHES, "'", $text)));

        return self::squeeze($text);
    }

    private static function nfkc(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return $text;
    }

    private static function squeeze(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
