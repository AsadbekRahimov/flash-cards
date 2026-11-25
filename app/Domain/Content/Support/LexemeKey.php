<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

/**
 * The catalog key of a word's spelling (docs/32 §2.1): the copies of one word in different courses and decks get the same
 * key, so they are one lexeme and earn word points once (docs/28). Deterministic and cheap on purpose - no lemmatiser, no AI:
 *
 *   - trimmed, lower-cased (`mb_strtolower`), inner whitespace collapsed to one space;
 *   - typographic apostrophes and dashes become ASCII (`’` → `'`, `–` → `-`);
 *   - the final punctuation (`.,!?;:`) and a leading article (`a `, `an `, `the `) are dropped, each only when something is left;
 *   - `to ` before a verb is KEPT: "to be" and "be" are two keys. An error towards "different" is the safe one - at worst the
 *     learner earns points for both spellings, within the same daily limits.
 *
 * Parts of speech are not told apart: "bank" (river) and "bank" (money) are one lexeme - one spelling, one attempt a day.
 * The key fits `lexemes.key` (100 characters, the length of `words.word`); a blank word has no key.
 */
final class LexemeKey
{
    public const MAX_LENGTH = 100;

    /** Apostrophe look-alikes: right / left single quotation mark, modifier letter apostrophe, grave and acute accents, prime. */
    private const APOSTROPHES = ['’', '‘', 'ʼ', '`', '´', '′'];

    /** Dash look-alikes: hyphen, non-breaking hyphen, figure dash, en dash, em dash, minus sign. */
    private const DASHES = ['‐', '‑', '‒', '–', '—', '−'];

    private const ARTICLES = ['a', 'an', 'the'];

    public static function of(string $word): ?string
    {
        $key = str_replace(self::APOSTROPHES, "'", $word);
        $key = str_replace(self::DASHES, '-', $key);
        // \s with /u covers the no-break and other Unicode spaces.
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $key)));

        $stripped = rtrim($key, '.,!?;: ');
        if ($stripped !== '') {
            $key = $stripped;
        }

        $space = strpos($key, ' ');
        if ($space !== false && in_array(substr($key, 0, $space), self::ARTICLES, true)) {
            $rest = ltrim(substr($key, $space + 1));
            if ($rest !== '') {
                $key = $rest;
            }
        }

        if ($key === '') {
            return null;
        }

        return mb_substr($key, 0, self::MAX_LENGTH);
    }
}
