<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Support\PracticeTextNormalizer;

/**
 * The rules of the texts of "Build the sentence" (docs/16 §5.3), in one place.
 *
 * PREPARING an issue ({@see forms()}): the allowed orders of the fragments become (a) their normalized texts, which an answer is
 * compared with, and (b) the forms in which they are shown after the answer. The first form is the author's `display` when there
 * is one, else the fragments joined by spaces with a capital letter and a full stop; the others are written the same way (a question
 * mark or an exclamation mark at the end of the `display` is kept for them). Orders with the same normalized text are one order:
 * the first of them stays, so `accepted[i]` and `accepted_display[i]` always belong together.
 *
 * GRADING ({@see grade()}): the answer is the ids of ALL the fragments, each once, in the order the learner put them. The text of
 * that order is compared with the allowed ones after the one normalization of the practice ({@see PracticeTextNormalizer}): case,
 * punctuation, apostrophes, hyphens and spaces make no mistake, and the same words are the same words whichever of their fragments
 * came first ("the cat sees the dog" has two "the", with two ids). An answer that is not such an order is not wrong but refused:
 * 422 `invalid_answer_shape` (a missing or a repeated fragment, not a list of ids) or `unknown_element_id` (an id that is no fragment
 * of the task); the learner can still answer.
 */
final class SentenceGrader
{
    /** The marks that end a sentence: no second full stop is put after one of them (a closing quote or bracket may follow). */
    private const ENDING = '/[.!?\x{2026}]["\'\x{00BB}\x{201D}\x{2019})\]]*$/u';

    /**
     * The normalized texts of the allowed orders and the forms they are shown in.
     *
     * @param  list<string>  $fragments  the texts, in the author's order (the sequences point into this list)
     * @param  list<list<int>>  $sequences  the allowed orders: permutations of the indices of $fragments
     * @param  string|null  $display  the author's own record of the FIRST allowed order (case, punctuation)
     * @return array{accepted: list<string>, accepted_display: list<string>}
     */
    public function forms(array $fragments, array $sequences, ?string $display = null): array
    {
        $display = trim((string) $display);
        $last = mb_substr($display, -1);
        $mark = $last === '?' || $last === '!' ? $last : '.';

        $accepted = [];
        $shown = [];
        foreach ($sequences as $sequence) {
            $parts = [];
            foreach ($sequence as $index) {
                if (isset($fragments[$index])) {
                    $parts[] = $fragments[$index];
                }
            }

            $text = implode(' ', $parts);
            $key = PracticeTextNormalizer::normalize($text);
            if ($key === '' || in_array($key, $accepted, true)) {
                continue;
            }

            $accepted[] = $key;
            $shown[] = $this->written($text, $mark);
        }

        if ($display !== '' && $shown !== []) {
            $shown[0] = $display;
        }

        return ['accepted' => $accepted, 'accepted_display' => $shown];
    }

    /**
     * Grades an answer: `{order: [fragment ids]}` or `{skip: true}`.
     *
     * @param  array<string, string>  $fragments  fragment id => its text, as the snapshot keeps them
     * @param  list<string>  $accepted  the texts of the allowed orders
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException invalid_answer_shape | unknown_element_id
     */
    public function grade(array $fragments, array $accepted, array $answer): SentenceGrade
    {
        if (($answer['skip'] ?? false) === true) {
            return new SentenceGrade(Verdict::Skipped, [], null);
        }

        $order = $answer['order'] ?? null;
        if (! is_array($order) || $order === [] || ! array_is_list($order)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_ORDER);
        }

        $ids = [];
        foreach ($order as $id) {
            if (! is_string($id)) {
                throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_ORDER);
            }
            $ids[] = $id;
        }

        foreach ($ids as $id) {
            if (! array_key_exists($id, $fragments)) {
                throw PracticeException::unknownElementId();
            }
        }

        // Every fragment exactly once: as many ids as fragments, no id twice.
        if (count($ids) !== count($fragments) || count(array_unique($ids)) !== count($ids)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_ORDER);
        }

        $text = $this->join($fragments, $ids);
        $same = in_array(
            PracticeTextNormalizer::normalize($text),
            array_map(PracticeTextNormalizer::normalize(...), $accepted),
            true,
        );

        return new SentenceGrade($same ? Verdict::Correct : Verdict::Wrong, $ids, $text);
    }

    /**
     * The text of an order: the fragments one after another, a space between. An id that is no fragment is left out.
     *
     * @param  array<string, string>  $fragments  fragment id => its text
     * @param  list<string>  $order
     */
    public function join(array $fragments, array $order): string
    {
        $parts = [];
        foreach ($order as $id) {
            if (array_key_exists($id, $fragments)) {
                $parts[] = $fragments[$id];
            }
        }

        return implode(' ', $parts);
    }

    /** The same text with a capital first letter (what the learner sees as their sentence). */
    public function capitalized(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** A sentence written out: single spaces, a capital letter, and `$mark` at the end unless the text already ends with a mark. */
    private function written(string $text, string $mark): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }

        $text = $this->capitalized($text);

        return preg_match(self::ENDING, $text) === 1 ? $text : $text.$mark;
    }
}
