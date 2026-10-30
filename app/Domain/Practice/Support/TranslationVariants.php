<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use Normalizer;

/**
 * What a translation of a word may be called, for "Find the pairs" (docs/16 §4.4): the keys by which two words CLASH, and the
 * text of the right-hand card.
 *
 * A translation is a string an author wrote: "мама, мамочка (разг.)", "Дом / здание", "ёж". It is read like this:
 *   1. the explanations in round brackets are cut out ("дом (здание)" -> "дом"; a bracket inside a bracket goes whole);
 *   2. what is left is split by `,` `;` `/` into VARIANTS; the empty ones are dropped;
 *   3. the KEY of a variant (to compare): NFKC -> lower case -> `ё` = `е` -> the closing `. ! ? …` and the white space at the end
 *      are cut -> one space between the words. A variant with an empty key is not a variant;
 *   4. the LABEL (what the card says): the FIRST variant as the author wrote it - the case and the `ё` stay.
 * A word without a variant in a locale (an empty translation, "(разг.)") is unfit for the pairs: nothing to put on a card.
 *
 * Two words clash in a round when their English keys are equal ({@see PracticeTextNormalizer::key()}) or the sets of the keys of
 * their variants intersect - in ANY locale of the set the snapshot is built for (a battle: the language of each player), where
 * the text of a locale is what the learner of that locale sees after the fallback to ru (WordTranslationResolver). So «Mother» and
 * «Mum», both «мама», never stand in one round - they stand in different ones - and two words that have no uz text and fall back
 * to the same ru text clash in uz as well.
 *
 * The variants of the BASE translation (`words.translation`, the last stop of the fallback) are keys of the word as well, whatever
 * the locales show: a uz learner who reads Russian sees «мама» on the card of «mum» (no uz text) and may well take it for the
 * translation of «mother» (uz «ona», base «мама») too. So the base is a tag of its own (`base|…`) and a word whose base says what
 * the other word says never shares a round with it. An empty base adds no key and does not make the word unfit.
 */
final class TranslationVariants
{
    /**
     * The keys of the variants, without repeats, in the order of the variants.
     *
     * @return list<string>
     */
    public static function keys(string $translation): array
    {
        $keys = [];
        foreach (self::variants($translation) as $variant) {
            $key = self::key($variant);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * The text of the card: the first variant as it was written, or null when there is none.
     */
    public static function label(string $translation): ?string
    {
        foreach (self::variants($translation) as $variant) {
            if (self::key($variant) !== '') {
                return $variant;
            }
        }

        return null;
    }

    /**
     * The keys by which a word clashes with another in a round: its English key, the keys of its variants in EACH locale (every
     * key carries its locale, so the same string in two languages is not a clash) and the keys of the variants of its base
     * translation (tagged `base`). Null: the word is unfit - no English text, or no variant of the translation in some locale.
     *
     * @param  array<string, string>  $translations  locale => the text of the translation shown in that locale (after the fallback)
     * @param  string  $base  `words.translation`: what the word falls back to; empty - no `base` key
     * @return list<string>|null
     */
    public static function clashKeys(string $english, array $translations, string $base = ''): ?array
    {
        $key = PracticeTextNormalizer::key($english);
        if ($key === '' || $translations === []) {
            return null;
        }

        $keys = ["en|{$key}"];
        foreach ($translations as $locale => $translation) {
            $variants = self::keys($translation);
            if ($variants === []) {
                return null;
            }
            foreach ($variants as $variant) {
                $keys[] = "{$locale}|{$variant}";
            }
        }
        foreach (self::keys($base) as $variant) {
            $keys[] = "base|{$variant}";
        }

        return $keys;
    }

    /**
     * Whether two words (their {@see clashKeys()}) clash.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function clash(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }

    /**
     * The variants as written: brackets cut out, split by `,` `;` `/`, trimmed, one space between the words, none empty.
     *
     * @return list<string>
     */
    private static function variants(string $translation): array
    {
        $text = $translation;
        do {
            $before = $text;
            $text = preg_replace('/\([^()]*\)/u', '', $text) ?? $before;
        } while ($text !== $before);

        $variants = [];
        foreach (preg_split('/[,;\/]/u', $text) ?: [] as $piece) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', $piece));
            if ($piece !== '') {
                $variants[] = $piece;
            }
        }

        return $variants;
    }

    private static function key(string $variant): string
    {
        $text = $variant;
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = str_replace('ё', 'е', mb_strtolower($text));
        $text = (string) preg_replace('/[\s.!?…]+$/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
