<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

use App\Models\Word;

/**
 * A phrase card (docs/35 §3.3, §3.4; release 7): a word of a phrase course with `part_of_speech = phrase` and the block `phrase` in
 * `words.meta` - `{kind, register, variant, gap, literal?, cefr?, frequency?, synonyms?}`. Everything here reads the row alone, no query.
 *
 * The key word of the gap (`meta.phrase.gap`) is matched as a whole word, case-insensitively; an apostrophe belongs to the word ("How's"
 * has no "how" of its own), a hyphen or a space ends it ("well-known" has "known"). The import accepts a gap that is one word a-z, stands
 * in the phrase exactly once and is not the whole phrase; the exercises mask that one occurrence.
 */
final class Phrase
{
    /** `words.part_of_speech` of a phrase card. */
    public const PART_OF_SPEECH = 'phrase';

    /** `courses.exam_tag` of a phrase course: the section "Phrases" of the catalog, never the main course of onboarding. */
    public const EXAM_TAG = 'phrases';

    /** The section of the catalog a course is listed in (`CourseResource.section`). */
    public const SECTION_COURSES = 'courses';

    public const SECTION_PHRASES = 'phrases';

    public const META = 'phrase';

    public const KINDS = ['formula', 'fixed', 'idiom', 'phrasal_verb', 'proverb'];

    public const REGISTERS = ['neutral', 'informal', 'formal', 'slang', 'rude'];

    public const VARIANTS = ['both', 'uk', 'us'];

    public const DEFAULT_VARIANT = 'both';

    public const CEFR = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    public const FREQUENCIES = [1, 2, 3];

    public const MAX_SYNONYMS = 10;

    /** What a gap may be: one word of Latin letters, no apostrophe, space or hyphen. */
    private const GAP_PATTERN = '/^[A-Za-z]+$/';

    /** The characters of a word around the gap: a letter, a digit, an apostrophe (straight or typographic). */
    private const WORD_CHAR = '[\p{L}\p{N}\'’]';

    public static function isPhrase(?string $partOfSpeech): bool
    {
        return $partOfSpeech === self::PART_OF_SPEECH;
    }

    public static function isPhraseCourse(?string $examTag): bool
    {
        return $examTag === self::EXAM_TAG;
    }

    public static function sectionOf(?string $examTag): string
    {
        return self::isPhraseCourse($examTag) ? self::SECTION_PHRASES : self::SECTION_COURSES;
    }

    public static function isPhraseWord(Word $word): bool
    {
        return self::isPhrase($word->part_of_speech);
    }

    /**
     * The block `phrase` of the word's meta, or null for a word that is not a phrase (or a phrase without the block).
     *
     * @return array<string, mixed>|null
     */
    public static function block(Word $word): ?array
    {
        if (! self::isPhraseWord($word)) {
            return null;
        }

        return self::blockOf($word->getAttribute('meta'));
    }

    /** @return array<string, mixed>|null */
    public static function blockOf(mixed $meta): ?array
    {
        $block = is_array($meta) ? ($meta[self::META] ?? null) : null;

        return is_array($block) ? $block : null;
    }

    /** The key word of the gap from a meta array (null when there is none, or it is not a valid word). */
    public static function gapOf(mixed $meta): ?string
    {
        $gap = self::blockOf($meta)['gap'] ?? null;

        return is_string($gap) && self::isGapWord($gap) ? $gap : null;
    }

    public static function isGapWord(string $gap): bool
    {
        return preg_match(self::GAP_PATTERN, $gap) === 1;
    }

    /** How many times the gap stands in the phrase as a whole word (case-insensitive). */
    public static function occurrences(string $phrase, string $gap): int
    {
        if (! self::isGapWord($gap)) {
            return 0;
        }

        return (int) preg_match_all(self::pattern($gap), $phrase);
    }

    /** Whether the gap is all the phrase has: nothing but punctuation and spaces is left without it. */
    public static function isWholePhrase(string $phrase, string $gap): bool
    {
        if (! self::isGapWord($gap)) {
            return false;
        }

        $rest = (string) preg_replace(self::pattern($gap), '', $phrase);

        return preg_match('/[\p{L}\p{N}]/u', $rest) !== 1;
    }

    /**
     * The phrase with its key word masked: `[masked text, the word as it stands in the phrase]`, or null when the gap does not stand in
     * the phrase exactly once, or is the whole phrase (the rules of the import - a phrase edited later in `/admin` may break them).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function mask(string $phrase, string $gap, string $mask): ?array
    {
        $phrase = trim($phrase);
        if (self::occurrences($phrase, $gap) !== 1 || self::isWholePhrase($phrase, $gap)) {
            return null;
        }

        $expected = null;
        $masked = preg_replace_callback(self::pattern($gap), static function (array $m) use (&$expected, $mask): string {
            $expected = $m[0];

            return $mask;
        }, $phrase);

        return $masked === null || $expected === null ? null : [$masked, $expected];
    }

    /**
     * The block of a card for the client (docs/04 A.3, F3): the kind, the register, UK / US and the literal translation in `$locale` (the
     * ru one when the locale has none). `$withLiteral` false hides the literal: it would give the answer of a choice card away.
     *
     * @return array{kind: string|null, register: string|null, variant: string, literal: string|null}|null
     */
    public static function forCard(Word $word, string $locale, bool $withLiteral): ?array
    {
        $block = self::block($word);
        if ($block === null) {
            return null;
        }

        $literal = null;
        if ($withLiteral && is_array($block['literal'] ?? null)) {
            $text = $block['literal'][$locale] ?? $block['literal']['ru'] ?? null;
            $literal = is_string($text) && trim($text) !== '' ? $text : null;
        }

        return [
            'kind' => is_string($block['kind'] ?? null) ? $block['kind'] : null,
            'register' => is_string($block['register'] ?? null) ? $block['register'] : null,
            'variant' => is_string($block['variant'] ?? null) ? $block['variant'] : self::DEFAULT_VARIANT,
            'literal' => $literal,
        ];
    }

    private static function pattern(string $gap): string
    {
        return '/(?<!'.self::WORD_CHAR.')'.preg_quote($gap, '/').'(?!'.self::WORD_CHAR.')/iu';
    }
}
