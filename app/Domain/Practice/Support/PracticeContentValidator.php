<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\Enums\PracticeItemType;
use App\Domain\Practice\Exceptions\PracticeContentException;
use Normalizer;

/**
 * The rules for the TEXT of a task (docs/16 §4.5): what the author wrote -> what a version stores in `content` (§2.3).
 * No database here: the course, the lesson, the target words and the key are checked by PracticeItemService.
 *
 * The author's form is the one of the import file (§15.1), strings only:
 *   sentence    {prompt: {ru, uz}, fragments: [..], accepted: [[fragment texts], ..], display?: str, explanation: {ru, uz}}
 *   collocation {text, options: [..3], correct: [option texts], translation: {ru, uz}, explanation: {ru, uz}}
 *   dictation   {text, accepted: [other accepted texts], translation: {ru, uz}, explanation: {ru, uz}|null}
 * The stored form replaces the sequences of the sentence by indices of the fragments and `correct` by indices of the
 * options, adds `text` as the first of the `accepted` of a dictation and keeps only the texts that are not empty.
 *
 * Every text is cleaned on the way in ({@see clean()}): control and invisible characters removed, NFC, one-line, trimmed - the
 * content comes from a file or a form and is displayed to learners (docs/16 §12). A text with `<` or `>` is REFUSED
 * (`markup_not_allowed`), never cut: stripping tags would silently drop what follows a lone `<` ("I <3 you" -> "I ").
 * The first fault found raises {@see PracticeContentException} with the code of §4.5 and the field it is in.
 *
 * {@see canonical()} is the check at saving a version; {@see assertPublishable()} is the stricter one at publishing
 * ("П" rows of §4.5): the stored content again through the same rules, plus a text in EVERY language where one is required.
 */
final class PracticeContentValidator
{
    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The stored `content` of a task of the type, from the author's form. Raises on the first fault.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     *
     * @throws PracticeContentException
     */
    public function canonical(PracticeItemType $type, array $content): array
    {
        return match ($type) {
            PracticeItemType::Sentence => $this->sentence($content),
            PracticeItemType::Collocation => $this->collocation($content),
            PracticeItemType::Dictation => $this->dictation($content),
        };
    }

    /**
     * The stricter check of publishing: the stored content must still pass the rules of saving (the limits may have been
     * tightened since) and carry a text in every supported language where docs/16 §4.5 asks for it: the prompt and the
     * explanation of a sentence, the translation and the explanation of a collocation, the translation of a dictation (its
     * explanation is optional).
     *
     * @param  array<string, mixed>  $content  as stored
     *
     * @throws PracticeContentException
     */
    public function assertPublishable(PracticeItemType $type, array $content): void
    {
        $canonical = $this->canonical($type, $this->authorForm($type, $content));

        $required = match ($type) {
            PracticeItemType::Sentence => ['prompt', 'explanation'],
            PracticeItemType::Collocation => ['translation', 'explanation'],
            PracticeItemType::Dictation => ['translation'],
        };

        foreach ($required as $field) {
            $texts = $canonical[$field] ?? [];

            foreach ($this->locales->supported() as $locale) {
                if (! is_array($texts) || ! isset($texts[$locale])) {
                    $this->fail(
                        PracticeContentException::LOCALE_TEXT_REQUIRED,
                        $field,
                        "The text '{$field}' is needed in the language '{$locale}' to publish.",
                        ['locale' => $locale],
                    );
                }
            }
        }
    }

    /**
     * The stored `content` turned back into the author's form (what the editor shows and what a repeated save compares to):
     * the indices of the sequences and of `correct` become the texts again.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function authorForm(PracticeItemType $type, array $content): array
    {
        if ($type === PracticeItemType::Sentence) {
            $fragments = $this->strings($content['fragments'] ?? []);
            $accepted = [];
            foreach (is_array($content['accepted'] ?? null) ? $content['accepted'] : [] as $sequence) {
                $accepted[] = array_map(
                    static fn (mixed $index): string => is_int($index) ? ($fragments[$index] ?? '') : '',
                    is_array($sequence) ? array_values($sequence) : [],
                );
            }

            return [...$content, 'accepted' => $accepted];
        }

        if ($type === PracticeItemType::Collocation) {
            $options = $this->strings($content['options'] ?? []);
            $correct = array_map(
                static fn (mixed $index): string => is_int($index) ? ($options[$index] ?? '') : '',
                is_array($content['correct'] ?? null) ? array_values($content['correct']) : [],
            );

            return [...$content, 'correct' => $correct];
        }

        return $content;
    }

    /**
     * One text of an author as it is stored: white space (tabs, line breaks, no-break spaces) and the other control and invisible
     * format characters (zero-width, bidi marks...) dealt with - the first as one space, the rest deleted - NFC, trimmed. An
     * invisible character would make the answer a learner types never match. It does not look at `<` and `>`: a text that has them
     * is refused before it comes here ({@see text()}).
     */
    public static function clean(string $value): string
    {
        $value = mb_scrub($value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $value);

        if (class_exists(Normalizer::class)) {
            $composed = Normalizer::normalize($value, Normalizer::FORM_C);
            if (is_string($composed)) {
                $value = $composed;
            }
        }

        return trim($value);
    }

    // --- sentence ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function sentence(array $in): array
    {
        $prompt = $this->localized($in['prompt'] ?? null, 'prompt', $this->limit('prompt_max'));
        if ($prompt === []) {
            $this->fail(PracticeContentException::TEXT_REQUIRED, 'prompt', 'The prompt needs a text in at least one language.');
        }

        $fragments = $this->fragments($in['fragments'] ?? null);
        $accepted = $this->acceptedSequences($fragments, $in['accepted'] ?? null);
        $display = $this->display($in['display'] ?? null, $fragments, $accepted[0]);
        $explanation = $this->localized($in['explanation'] ?? null, 'explanation', $this->limit('explanation_max'));

        $content = ['prompt' => $prompt, 'fragments' => $fragments, 'accepted' => $accepted];
        if ($display !== null) {
            $content['display'] = $display;
        }
        $content['explanation'] = $explanation;

        return $content;
    }

    /** @return list<string> */
    private function fragments(mixed $raw): array
    {
        $min = $this->limit('sentence_fragments.min');
        $max = $this->limit('sentence_fragments.max');

        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < $min || count($raw) > $max) {
            $this->fail(PracticeContentException::FRAGMENTS_COUNT, 'fragments', "A sentence needs {$min}..{$max} fragments.", ['min' => $min, 'max' => $max]);
        }

        $longest = $this->limit('fragment_max_length');
        $fragments = [];
        foreach ($raw as $i => $fragment) {
            $index = $i + 1;

            // A line break is checked BEFORE the text is cleaned: cleaning would turn it into a space and hide the mistake.
            if (! is_string($fragment) || preg_match('/[\r\n]/', $fragment) === 1) {
                $this->fail(PracticeContentException::FRAGMENT_INVALID, 'fragments', "Fragment {$index} is not a one-line text.", ['index' => $index, 'max' => $longest]);
            }

            $clean = $this->text($fragment, 'fragments', ['index' => $index, 'max' => $longest]);
            if (PracticeTextNormalizer::normalize($clean) === '' || mb_strlen($clean) > $longest) {
                $this->fail(PracticeContentException::FRAGMENT_INVALID, 'fragments', "Fragment {$index} is empty, only punctuation or longer than {$longest} characters.", ['index' => $index, 'max' => $longest]);
            }

            $fragments[] = $clean;
        }

        return $fragments;
    }

    /**
     * The accepted sequences as lists of fragment indices. A sequence is written with the TEXTS of the fragments; a text is
     * matched to the first unused fragment with the same normalized text (so equal fragments are matched in order of
     * appearance - which makes no difference to grading, the text is compared). Sequences that read the same are kept once;
     * a list that is nothing but one sequence written twice or more is a slip of the author (a variant that was meant to
     * differ) and is refused.
     *
     * The FIRST accepted sequence is the order of the fragments (`accepted_first_mismatch` otherwise): the review of an answer shows
     * the first as the right one, and the fragments are written in the order the author means them. A list that starts with
     * another order would put the order of the fragments in a place where it may be left out, and it would then be graded wrong.
     *
     * @param  list<string>  $fragments
     * @return list<list<int>>
     */
    private function acceptedSequences(array $fragments, mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            $this->fail(PracticeContentException::ACCEPTED_REQUIRED, 'accepted', 'A sentence needs at least one accepted order of the fragments.');
        }

        $normalized = array_map(PracticeTextNormalizer::normalize(...), $fragments);

        /** @var array<string, list<int>> $distinct reading of the sequence => its indices, in the order of the author */
        $distinct = [];
        foreach ($raw as $n => $sequence) {
            if (! is_array($sequence) || ! array_is_list($sequence) || count($sequence) !== count($fragments)) {
                $this->notPermutation($n + 1);
            }

            $used = [];
            $indices = [];
            $words = [];
            foreach ($sequence as $piece) {
                $needle = is_string($piece) ? PracticeTextNormalizer::normalize(self::clean($piece)) : null;
                $found = null;
                foreach ($normalized as $i => $candidate) {
                    if (! isset($used[$i]) && $candidate === $needle) {
                        $found = $i;
                        break;
                    }
                }
                if ($found === null) {
                    $this->notPermutation($n + 1);
                }

                $used[$found] = true;
                $indices[] = $found;
                $words[] = $fragments[$found];
            }

            $reading = PracticeTextNormalizer::normalize(implode(' ', $words));
            $distinct[$reading] ??= $indices;
        }

        if ((string) array_key_first($distinct) !== PracticeTextNormalizer::normalize(implode(' ', $fragments))) {
            $this->fail(PracticeContentException::ACCEPTED_FIRST_MISMATCH, 'accepted', 'The first accepted order must be the order of the fragments.');
        }

        if (count($raw) > 1 && count($distinct) === 1) {
            $this->fail(PracticeContentException::ACCEPTED_DUPLICATE, 'accepted', 'The accepted orders are one order written several times.');
        }

        $max = $this->limit('sentence_accepted_max');
        if (count($distinct) > $max) {
            $this->fail(PracticeContentException::ACCEPTED_TOO_MANY, 'accepted', "A sentence takes at most {$max} accepted orders.", ['max' => $max]);
        }

        return array_values($distinct);
    }

    /**
     * The optional canonical writing of the first accepted order, for the review of an answer: it must read exactly as that
     * order does (docs/16 §4.5 `display_mismatch`) - the review must not contradict the grading.
     *
     * @param  list<string>  $fragments
     * @param  list<int>  $first  the indices of the first accepted order
     */
    private function display(mixed $raw, array $fragments, array $first): ?string
    {
        if ($raw === null) {
            return null;
        }

        $display = is_string($raw) ? $this->text($raw, 'display') : null;
        if ($display === '') {
            return null;
        }

        $longest = $this->limit('prompt_max');
        if ($display !== null && mb_strlen($display) > $longest) {
            $this->fail(PracticeContentException::TEXT_TOO_LONG, 'display', "The display text is longer than {$longest} characters.", ['max' => $longest]);
        }

        // Not a text at all (a number, a list) reads like nothing: it cannot match.
        $expected = PracticeTextNormalizer::normalize(implode(' ', array_map(static fn (int $i): string => $fragments[$i], $first)));
        if ($display === null || PracticeTextNormalizer::normalize($display) !== $expected) {
            $this->fail(PracticeContentException::DISPLAY_MISMATCH, 'display', 'The display text does not read like the first accepted order.');
        }

        return $display;
    }

    private function notPermutation(int $number): never
    {
        $this->fail(
            PracticeContentException::ACCEPTED_NOT_PERMUTATION,
            'accepted',
            "Accepted order {$number} is not a permutation of all the fragments.",
            ['index' => $number],
        );
    }

    // --- collocation ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function collocation(array $in): array
    {
        $text = $this->requiredText($in['text'] ?? null, 'text', $this->limit('collocation_text_max'));

        // Exactly one gap of exactly three underscores: the full text is made by putting the correct option in its place.
        if (preg_match_all('/_{3,}/', $text, $gaps) !== 1 || $gaps[0][0] !== '___') {
            $this->fail(PracticeContentException::PLACEHOLDER_INVALID, 'text', 'The text needs exactly one gap written as ___.');
        }

        $options = $this->options($in['options'] ?? null);
        $correct = $this->correct($options, $in['correct'] ?? null);

        return [
            'text' => $text,
            'options' => $options,
            'correct' => $correct,
            'translation' => $this->localized($in['translation'] ?? null, 'translation', $this->limit('prompt_max')),
            'explanation' => $this->localized($in['explanation'] ?? null, 'explanation', $this->limit('explanation_max')),
        ];
    }

    /** @return list<string> */
    private function options(mixed $raw): array
    {
        $required = $this->limit('collocation_options');
        $options = [];
        foreach (is_array($raw) && array_is_list($raw) ? $raw : [] as $option) {
            $clean = is_string($option) ? $this->text($option, 'options') : '';
            if ($clean !== '') {
                $options[] = $clean;
            }
        }

        if (count($options) !== $required) {
            $this->fail(PracticeContentException::OPTIONS_COUNT, 'options', "A collocation needs exactly {$required} options.", ['count' => $required]);
        }

        $longest = $this->limit('fragment_max_length');
        $seen = [];
        foreach ($options as $option) {
            if (mb_strlen($option) > $longest) {
                $this->fail(PracticeContentException::TEXT_TOO_LONG, 'options', "An option is longer than {$longest} characters.", ['max' => $longest]);
            }

            $reading = PracticeTextNormalizer::normalize($option);
            if (isset($seen[$reading])) {
                $this->fail(PracticeContentException::OPTIONS_DUPLICATE, 'options', 'Two options read the same.', ['option' => $option]);
            }
            $seen[$reading] = true;
        }

        return $options;
    }

    /**
     * The indices of the correct options. A correct answer is named by the text of its option; at least one and not all.
     *
     * @param  list<string>  $options
     * @return list<int>
     */
    private function correct(array $options, mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            $this->fail(PracticeContentException::CORRECT_INVALID, 'correct', 'A collocation needs at least one correct option.');
        }

        $normalized = array_map(PracticeTextNormalizer::normalize(...), $options);
        $indices = [];
        foreach ($raw as $answer) {
            $index = is_string($answer) ? array_search(PracticeTextNormalizer::normalize(self::clean($answer)), $normalized, true) : false;
            if ($index === false) {
                $this->fail(PracticeContentException::CORRECT_INVALID, 'correct', 'A correct answer is not one of the options.', ['answer' => is_string($answer) ? $answer : '']);
            }

            $indices[$index] = $index;
        }

        if (count($indices) >= count($options)) {
            $this->fail(PracticeContentException::ALL_OPTIONS_CORRECT, 'correct', 'Not all the options can be correct.');
        }

        sort($indices);

        return $indices;
    }

    // --- dictation --------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private function dictation(array $in): array
    {
        $text = $this->requiredText($in['text'] ?? null, 'text', $this->limit('dictation_text_max'));

        // `text` is the first accepted writing; a variant that reads like one already listed (the text with its full stop
        // left out) is the same answer and is kept once.
        $accepted = [$text];
        $seen = [PracticeTextNormalizer::normalize($text) => true];
        $raw = $in['accepted'] ?? [];
        // `cause` tells the admin panel which of the faults of this code it is (the sentences differ): shape, empty, length, count.
        if (! is_array($raw)) {
            $this->fail(PracticeContentException::DICTATION_ACCEPTED_INVALID, 'accepted', 'The accepted writings must be a list of texts.', ['cause' => 'shape']);
        }

        $longest = $this->limit('dictation_text_max');
        foreach ($raw as $variant) {
            $clean = is_string($variant) ? $this->text($variant, 'accepted') : '';
            $reading = PracticeTextNormalizer::normalize($clean);
            if ($reading === '') {
                $this->fail(PracticeContentException::DICTATION_ACCEPTED_INVALID, 'accepted', 'An accepted writing is empty.', ['cause' => 'empty']);
            }
            if (mb_strlen($clean) > $longest) {
                $this->fail(PracticeContentException::DICTATION_ACCEPTED_INVALID, 'accepted', "An accepted writing is longer than {$longest} characters.", ['cause' => 'length', 'max' => $longest]);
            }

            if (! isset($seen[$reading])) {
                $seen[$reading] = true;
                $accepted[] = $clean;
            }
        }

        $extra = $this->limit('dictation_accepted_max');
        if (count($accepted) - 1 > $extra) {
            $this->fail(PracticeContentException::DICTATION_ACCEPTED_INVALID, 'accepted', "A dictation takes at most {$extra} accepted writings besides the text.", ['cause' => 'count', 'max' => $extra]);
        }

        $explanation = $this->localized($in['explanation'] ?? null, 'explanation', $this->limit('explanation_max'));

        return [
            'text' => $text,
            'accepted' => $accepted,
            'translation' => $this->localized($in['translation'] ?? null, 'translation', $this->limit('prompt_max')),
            'explanation' => $explanation === [] ? null : $explanation,
        ];
    }

    // --- shared -----------------------------------------------------------------------------------------------------

    /**
     * A text the author wrote, cleaned ({@see clean()}). One with `<` or `>` is refused: no text of the practice is markup, and
     * `strip_tags` would take a lone `<` for the start of a tag and drop all that follows it, without a word to the author.
     *
     * @param  array<string, int|string>  $context
     *
     * @throws PracticeContentException markup_not_allowed
     */
    private function text(string $value, string $field, array $context = []): string
    {
        if (str_contains($value, '<') || str_contains($value, '>')) {
            $this->fail(PracticeContentException::MARKUP_NOT_ALLOWED, $field, "The text '{$field}' has a '<' or '>': markup is not allowed.", $context);
        }

        return self::clean($value);
    }

    /** A text that must be there (and is not only punctuation) and not longer than `$max`. */
    private function requiredText(mixed $raw, string $field, int $max): string
    {
        $text = is_string($raw) ? $this->text($raw, $field) : '';
        if (PracticeTextNormalizer::normalize($text) === '') {
            $this->fail(PracticeContentException::TEXT_REQUIRED, $field, "The text '{$field}' is required.");
        }

        if (mb_strlen($text) > $max) {
            $this->fail(PracticeContentException::TEXT_TOO_LONG, $field, "The text '{$field}' is longer than {$max} characters.", ['max' => $max]);
        }

        return $text;
    }

    /**
     * The texts of a field by language (only the supported ones, only those that are not empty), each not longer than `$max`.
     *
     * @return array<string, string>
     */
    private function localized(mixed $raw, string $field, int $max): array
    {
        $texts = [];
        if (is_array($raw)) {
            foreach ($this->locales->supported() as $locale) {
                $value = $raw[$locale] ?? null;
                $clean = is_string($value) ? $this->text($value, $field, ['locale' => $locale]) : '';
                if ($clean === '') {
                    continue;
                }

                if (mb_strlen($clean) > $max) {
                    $this->fail(PracticeContentException::TEXT_TOO_LONG, $field, "The text '{$field}' ({$locale}) is longer than {$max} characters.", ['max' => $max, 'locale' => $locale]);
                }

                $texts[$locale] = $clean;
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        $strings = [];
        foreach (is_array($value) ? array_values($value) : [] as $item) {
            $strings[] = is_string($item) ? $item : '';
        }

        return $strings;
    }

    private function limit(string $key): int
    {
        return (int) config('practice.content.'.$key);
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function fail(string $code, string $field, string $message, array $context = []): never
    {
        throw new PracticeContentException($code, $field, $message, $context);
    }
}
