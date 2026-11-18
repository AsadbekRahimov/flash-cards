<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\Exceptions\PracticeException;

/**
 * Grades the two steps of a "find the mistake" element (docs/16 §5.7). Pure functions of the ids of the snapshot and the answer: the
 * ids are opaque (`t…` the words, `o…` the corrections), so what the learner sends can only be compared with them. `{skip: true}` is
 * the mode's to handle (it ends the element at either step); these see only the answer of a step.
 *
 * - step `locate` - `{token_id}`: one of THIS element's words, else 422 `unknown_element_id`; right <=> it is the wrong word. An
 *   `{option_id}` instead: the answer is for the next step - 409 `step_not_current {step: locate}`; nothing: 422
 *   `invalid_answer_shape {expected: token_id}`;
 * - step `fix` - `{option_id}`: one of the element's corrections, else 422 `unknown_element_id`; right <=> it is the right one. A
 *   `{token_id}` instead: 409 `step_not_current {step: fix}` (the word was found already: another tab, a lost answer); nothing: 422
 *   `invalid_answer_shape {expected: option_id}`.
 */
final class ErrorHuntGrader
{
    /**
     * @param  list<string>  $tokenIds  the ids of the words of the element
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException unknown_element_id | step_not_current | invalid_answer_shape
     */
    public function locate(array $tokenIds, string $errorId, array $answer): bool
    {
        $tokenId = self::id($answer, 'token_id');
        if ($tokenId === null) {
            throw self::id($answer, 'option_id') !== null
                ? PracticeException::stepNotCurrent(PracticeException::STEP_LOCATE)
                : PracticeException::invalidAnswerShape(PracticeException::EXPECTED_TOKEN);
        }
        if (! in_array($tokenId, $tokenIds, true)) {
            throw PracticeException::unknownElementId();
        }

        return $tokenId === $errorId;
    }

    /**
     * @param  list<string>  $optionIds  the ids of the corrections of the element
     * @param  list<string>  $correctIds  the id(s) of the right one
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException unknown_element_id | step_not_current | invalid_answer_shape
     */
    public function fix(array $optionIds, array $correctIds, array $answer): bool
    {
        $optionId = self::id($answer, 'option_id');
        if ($optionId === null) {
            throw self::id($answer, 'token_id') !== null
                ? PracticeException::stepNotCurrent(PracticeException::STEP_FIX)
                : PracticeException::invalidAnswerShape(PracticeException::EXPECTED_OPTION);
        }
        if (! in_array($optionId, $optionIds, true)) {
            throw PracticeException::unknownElementId();
        }

        return in_array($optionId, $correctIds, true);
    }

    /** @param  array<string, mixed>  $answer */
    private static function id(array $answer, string $key): ?string
    {
        $id = $answer[$key] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
