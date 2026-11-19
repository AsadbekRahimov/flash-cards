<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Support\PracticeTextNormalizer;
use App\Domain\Practice\Support\SpellingVariants;

/**
 * Grades the form of a word the learner typed (docs/16 §5.6): `correct`, `wrong` or `skipped` - there is NO `typo`. A pure
 * function of the right forms, the base word and the answer.
 *
 * - `{skip: true}`, or a text with nothing but punctuation and spaces: `skipped`;
 * - `{text}`: the tokens of the answer (PracticeTextNormalizer: case, apostrophes, the hyphen as a space, the listed punctuation;
 *   only the first {@see MAX_GRADED} characters are looked at) against the tokens of every right form: as many tokens, each the same
 *   or its other spelling ({@see SpellingVariants}: `colour` / `color`) -> `correct`; else `wrong`, and when the answer reads like the
 *   base word the review says so (`same_as_base`: "another form of the word is needed");
 * - anything else: 422 `invalid_answer_shape` `{expected: text_or_skip}`.
 *
 * Why no typo: the lexicon that forgives a slip in a dictation takes exactly the badly built forms (`happyer`, `stoped`, `writting`)
 * for "not a word", and here the building of the form IS the skill being checked. A slip in the root is a mistake too: it costs one
 * mistake of a practice that touches neither the SRS nor the rating, and "Repeat the mistakes" brings the task back.
 *
 * `reveal` holds what the grader knows - `your_text` (null when skipped) and `same_as_base`; the mode adds the right forms and the
 * texts of the element. `points`: 100 / 0 (the interface; no battle plays the mode).
 */
final class WordFormGrader
{
    /** The longest "your text" kept and shown back. */
    public const MAX_TEXT = 200;

    /** The part of the answer that is graded: a right form is at most 3 words of 40 characters. */
    public const MAX_GRADED = 60;

    /**
     * @param  list<string>  $accepted  the right forms, the canonical one first
     * @param  array<string, mixed>  $answer  as the client sent it
     *
     * @throws PracticeException invalid_answer_shape
     */
    public function grade(array $accepted, string $base, array $answer): GradeResult
    {
        $skip = ($answer['skip'] ?? null) === true;
        $text = $answer['text'] ?? null;
        if (! $skip && ! is_string($text)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_TEXT_OR_SKIP);
        }

        $yourText = $skip ? '' : trim(mb_substr(trim((string) $text), 0, self::MAX_TEXT));
        $tokens = PracticeTextNormalizer::tokens(mb_substr($yourText, 0, self::MAX_GRADED));

        if ($tokens === []) {
            return new GradeResult(Verdict::Skipped, ['your_text' => null, 'same_as_base' => false], ['skip' => true]);
        }

        $correct = false;
        foreach ($accepted as $variant) {
            if (self::sameTokens($tokens, PracticeTextNormalizer::tokens($variant))) {
                $correct = true;

                break;
            }
        }

        return new GradeResult(
            verdict: $correct ? Verdict::Correct : Verdict::Wrong,
            reveal: ['your_text' => $yourText, 'same_as_base' => ! $correct && self::sameTokens($tokens, PracticeTextNormalizer::tokens($base))],
            normalizedAnswer: ['text' => $yourText],
            points: $correct ? max(0, (int) config('practice.battle.points.round', 100)) : 0,
        );
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function sameTokens(array $a, array $b): bool
    {
        if ($b === [] || count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $token) {
            if (! SpellingVariants::same($token, $b[$i])) {
                return false;
            }
        }

        return true;
    }
}
