<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;

/**
 * Grades the answer to a "pick the collocation" element (docs/16 §5.4). A pure function of the ids of the snapshot and the
 * answer, no database, no text: the ids are opaque (`o…`), so what the learner sends can only be compared with them.
 *
 * - `{option_id}`: the id must be one of THIS element's options, otherwise 422 `unknown_element_id` (an id made up, taken
 *   from another element or from another session is the same lie); correct <=> it is one of the right ones - there are one
 *   or two right options, and either counts;
 * - `{skip: true}`: "I don't know" - a hint and a mistake, `skipped`;
 * - anything else (no `option_id`, not a string, an empty one): 422 `invalid_answer_shape`.
 *
 * A battle round is worth `practice.battle.points.round` for a right answer and nothing for a wrong or skipped one
 * (docs/15 §3.5); a solo session ignores `points`. The explanation (the whole phrase, the translation) is the mode's: the
 * grader judges and says what to store as the answer.
 */
final class CollocationGrader
{
    /**
     * @param  list<string>  $optionIds  the ids of the options of the element
     * @param  list<string>  $correctIds  the ids of the right ones (a subset of `$optionIds`)
     * @param  array<string, mixed>  $answer  as the client sent it
     *
     * @throws PracticeException invalid_answer_shape | unknown_element_id
     */
    public function grade(array $optionIds, array $correctIds, array $answer): GradeResult
    {
        if (($answer['skip'] ?? false) === true) {
            return new GradeResult(Verdict::Skipped, null, ['skip' => true]);
        }

        $optionId = $answer['option_id'] ?? null;
        if (! is_string($optionId) || $optionId === '') {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_OPTION);
        }
        if (! in_array($optionId, $optionIds, true)) {
            throw PracticeException::unknownElementId();
        }

        $correct = in_array($optionId, $correctIds, true);

        return new GradeResult(
            verdict: $correct ? Verdict::Correct : Verdict::Wrong,
            reveal: null,
            normalizedAnswer: ['option_id' => $optionId],
            points: $correct ? max(0, (int) config('practice.battle.points.round')) : 0,
        );
    }
}
