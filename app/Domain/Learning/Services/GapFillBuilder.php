<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\GapFill;
use App\Models\Word;

/**
 * Masks the word in its example for the gap-fill exercise (docs/13 §2.5, docs/06 §4.3).
 *
 * Every occurrence of the word or one of its regular forms — `+s`, `+es`, `+ed`, `+d`, `+ing`,
 * `y → ies / ied`, `e → ing` — is matched as a whole word, case-insensitively, and replaced by
 * {@see MASK}; the first matched form (as written in the example) is what the learner has to type.
 * No example, or the word not in it (irregular form, a paraphrase) → null, and the selector
 * falls back to another exercise. Pure: the same word always gives the same mask.
 */
final class GapFillBuilder
{
    public const MASK = '____';

    public function build(Word $word): ?GapFill
    {
        $example = trim((string) $word->example);
        $base = trim($word->word);
        if ($example === '' || $base === '') {
            return null;
        }

        $forms = array_map(static fn (string $f): string => preg_quote($f, '/'), $this->forms($base));
        // Longest first: "walked" must not be matched as "walk" + "ed".
        usort($forms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $pattern = '/(?<![\p{L}\p{N}])(?:'.implode('|', $forms).')(?![\p{L}\p{N}])/iu';
        $expected = null;
        $masked = preg_replace_callback($pattern, static function (array $m) use (&$expected): string {
            $expected ??= $m[0];

            return self::MASK;
        }, $example);
        if ($masked === null || $expected === null) {
            return null;
        }

        return new GapFill($masked, $expected);
    }

    /**
     * The base word and its regular inflections.
     *
     * @return list<string>
     */
    public function forms(string $base): array
    {
        $forms = [$base, $base.'s', $base.'es', $base.'ed', $base.'d', $base.'ing'];

        $last = mb_substr($base, -1);
        $stem = mb_substr($base, 0, -1);
        if ($last === 'y' && $stem !== '') {
            $forms[] = $stem.'ies';
            $forms[] = $stem.'ied';
        }
        if ($last === 'e' && $stem !== '') {
            $forms[] = $stem.'ing';
        }

        return array_values(array_unique($forms));
    }
}
