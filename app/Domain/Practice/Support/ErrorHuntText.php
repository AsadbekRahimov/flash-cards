<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

/**
 * The words of a "find the mistake" sentence (docs/16 §1.2 of the project, §5.7): the stored text is split at its single spaces
 * (PracticeContentValidator::clean leaves one between words), the punctuation stays part of its token (`art.`). The CORE of a
 * token is the token without the punctuation at its ends (`"informations".` -> `informations`; the apostrophe inside a word
 * stays: `it's`) - it is what the author marks as the mistake and what a correction replaces, the punctuation around it is kept.
 * Pure functions; the validator, the mode and the checks of the packs share them.
 */
final class ErrorHuntText
{
    /** The punctuation a core is taken out of: `. , ! ? ; : … " ' ( ) [ ]` and the typographic quotes. */
    private const EDGE = '.,!?;:…"\'()\[\]“”«»';

    /**
     * The tokens of a stored text: split at the spaces.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $text = trim($text);

        return $text === '' ? [] : array_values(array_filter(explode(' ', $text), static fn (string $t): bool => $t !== ''));
    }

    /**
     * The token in three parts: the punctuation before the core, the core, the punctuation after it.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function split(string $token): array
    {
        $edge = self::EDGE;
        if (preg_match('/\A(['.$edge.']*)(.*?)(['.$edge.']*)\z/u', $token, $m) !== 1) {
            return ['', $token, ''];
        }

        return [$m[1], $m[2], $m[3]];
    }

    public static function core(string $token): string
    {
        return self::split($token)[1];
    }

    /**
     * The sentence with the core of the token `$index` replaced by `$replacement` (the punctuation around it kept).
     *
     * @param  list<string>  $tokens
     */
    public static function replace(array $tokens, int $index, string $replacement): string
    {
        if (isset($tokens[$index])) {
            [$before, , $after] = self::split($tokens[$index]);
            $tokens[$index] = $before.$replacement.$after;
        }

        return implode(' ', $tokens);
    }

    /**
     * The author's writing of the stored text: the core of the token `$index` between asterisks.
     *
     * @param  list<string>  $tokens
     */
    public static function marked(array $tokens, int $index): string
    {
        if (! isset($tokens[$index])) {
            return implode(' ', $tokens);
        }

        return self::replace($tokens, $index, '*'.self::core($tokens[$index]).'*');
    }
}
