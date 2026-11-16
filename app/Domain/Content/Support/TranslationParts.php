<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

/**
 * The senses an authored translation names, for comparing two translations (docs/06 §12.2, §13.1): "u, bu" names the senses
 * `u` and `bu`; "оно, это" names `оно` and `это`. Two words whose translations share a sense in the learner's language are
 * both right answers to the same question ("u" → he / she; "смотреть" → watch / look), so the four-option exercises never
 * stand them against each other and a pick of the other one is graded right.
 *
 * Reading: the explanations in round brackets are cut out ("дом (здание)" → "дом"), the rest is split by `,` `;` `/`, and each
 * part is keyed case-insensitively with the white space collapsed, `ё` = `е`, the closing `. ! ? …` cut. An empty part is no
 * sense; a text without a sense (empty, "(разг.)") has none. The pairs practice reads its translations the same way
 * (`Practice\Support\TranslationVariants`); the two stay apart so that neither domain imports the other.
 */
final class TranslationParts
{
    /**
     * The keys of the senses, without repeats, in the order written.
     *
     * @return list<string>
     */
    public static function keys(string $translation): array
    {
        $plain = (string) preg_replace('/\([^()]*\)/u', ' ', $translation);
        $keys = [];

        foreach (preg_split('/[,;\/]+/u', $plain) ?: [] as $part) {
            $key = self::key($part);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * Whether two translations name a common sense.
     *
     * @param  list<string>  $keys  {@see keys()} of one of them
     */
    public static function share(array $keys, string $other): bool
    {
        if ($keys === []) {
            return false;
        }

        return array_intersect($keys, self::keys($other)) !== [];
    }

    /** The key of one sense: lower case, white space collapsed, `ё` = `е`, closing punctuation and spaces cut. */
    public static function key(string $part): string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $part)));
        $key = str_replace('ё', 'е', $key);

        return rtrim($key, ' .!?…');
    }
}
