<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\DTO\PairsRound;
use App\Domain\Practice\DTO\SnapshotElement;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\PracticeException;

/**
 * Grades a round of "Find the pairs" (docs/16 §5.5). Pure: the table `pairs` of the element and the state of the round are all it
 * reads. Two forms of the answer, two kinds of round - the round itself says which (`PairsRound::$battle`, set when the snapshot of a
 * battle is built), so a solo client cannot hand in a whole table and a battle client cannot play one match at a time (422).
 *
 * SOLO - one choice `{left_id, right_id}`, graded at once:
 *   - both ids belong to the round, else 422 `unknown_element_id`; ids missing, 422 `invalid_answer_shape` (`left_right`);
 *   - a word that is matched already, or a translation that is taken already, is 409 `pair_already_matched`: a second tap on a pair
 *     that is done counts for nothing, so a double tap cannot raise the accuracy of the first tries;
 *   - right: the pair is `matched`; wrong: `mismatches` grows, and the right answer is NOT shown (the learner looks for it);
 *   - the FIRST mistake on a word is written to `wrong_left` once and stays there after the word is matched; the match that follows a
 *     mistake is not a first try (`firstAttempt = false`), so trying again never raises the accuracy of the first tries;
 *   - the round is `final` when every word is matched. The new state of the round is `GradeResult::$extra`, which the session
 *     service keeps as `state.pairs[element]`: `{matched: {left: right}, mismatches, wrong_left: [left]}`.
 *
 * BATTLE - the whole table `{pairs: {left_id: right_id, ...}}` as ONE final answer: every word once, no translation twice (a
 * bijection), else 422 `invalid_answer_shape` (`bijection`) - or `unknown_element_id` for an id that is not in the round; 25 points
 * for each pair that is right (`practice.battle.points.pair`, at most 100 for a round), `correct` only when all of them are. The reveal
 * - the right table and the words matched right - is for the battle to show once the round is resolved: never with the answer.
 */
final class PairsGrader
{
    /**
     * @param  array<string, mixed>  $answer
     *
     * @throws PracticeException
     */
    public function grade(SnapshotElement $element, array $answer, ElementState $state): GradeResult
    {
        $round = PairsRound::of($element);

        return $round->battle ? $this->gradeSet($round, $answer) : $this->gradeChoice($round, $answer, $state);
    }

    /**
     * One match of a solo round.
     *
     * @param  array<string, mixed>  $answer  `{left_id, right_id}`
     *
     * @throws PracticeException
     */
    public function gradeChoice(PairsRound $round, array $answer, ElementState $state): GradeResult
    {
        $leftId = $answer['left_id'] ?? null;
        $rightId = $answer['right_id'] ?? null;
        if (! is_string($leftId) || $leftId === '' || ! is_string($rightId) || $rightId === '') {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_LEFT_RIGHT);
        }
        if (! in_array($leftId, $round->leftIds(), true) || ! in_array($rightId, $round->rightIds(), true)) {
            throw PracticeException::unknownElementId();
        }

        $matched = PairsRound::matched($state);
        if (isset($matched[$leftId])) {
            throw PracticeException::pairAlreadyMatched($leftId);
        }
        $owner = array_search($rightId, $matched, true);
        if ($owner !== false) {
            throw PracticeException::pairAlreadyMatched((string) $owner);
        }

        $wrongLeft = PairsRound::wrongLeft($state);
        $mismatches = PairsRound::mismatches($state);
        $first = ! in_array($leftId, $wrongLeft, true);
        $right = ($round->table[$leftId] ?? null) === $rightId;

        if ($right) {
            $matched[$leftId] = $rightId;
        } else {
            $mismatches++;
            if ($first) {
                $wrongLeft[] = $leftId;
            }
        }

        return new GradeResult(
            verdict: $right ? Verdict::Correct : Verdict::Wrong,
            reveal: null,
            normalizedAnswer: ['left_id' => $leftId, 'right_id' => $rightId],
            final: count($matched) >= count($round->left),
            points: 0,
            firstAttempt: $first,
            extra: ['matched' => $matched, 'mismatches' => $mismatches, 'wrong_left' => $wrongLeft],
        );
    }

    /**
     * The whole table of a battle round.
     *
     * @param  array<string, mixed>  $answer  `{pairs: {left_id: right_id}}`
     *
     * @throws PracticeException
     */
    public function gradeSet(PairsRound $round, array $answer): GradeResult
    {
        $given = $answer['pairs'] ?? null;
        if (! is_array($given)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_BIJECTION);
        }

        $lefts = $round->leftIds();
        $rights = $round->rightIds();
        $chosen = [];
        foreach ($given as $leftId => $rightId) {
            if (! is_string($rightId)) {
                throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_BIJECTION);
            }
            if (! in_array((string) $leftId, $lefts, true) || ! in_array($rightId, $rights, true)) {
                throw PracticeException::unknownElementId();
            }
            $chosen[(string) $leftId] = $rightId;
        }
        // Every word once (the keys are distinct by nature), no translation twice: with the counts equal, a bijection.
        if (count($chosen) !== count($lefts) || count(array_unique($chosen)) !== count($chosen)) {
            throw PracticeException::invalidAnswerShape(PracticeException::EXPECTED_BIJECTION);
        }

        $inOrder = [];
        $right = [];
        foreach ($lefts as $leftId) {
            $inOrder[$leftId] = $chosen[$leftId];
            if (($round->table[$leftId] ?? null) === $chosen[$leftId]) {
                $right[] = $leftId;
            }
        }

        $perPair = max(0, (int) config('practice.battle.points.pair', 25));
        $perRound = max(0, (int) config('practice.battle.points.round', 100));

        return new GradeResult(
            verdict: count($right) === count($lefts) ? Verdict::Correct : Verdict::Wrong,
            reveal: ['pairs' => $round->table, 'correct_left' => $right],
            normalizedAnswer: ['pairs' => $inOrder],
            final: true,
            points: min($perRound, $perPair * count($right)),
            firstAttempt: true,
            extra: null,
        );
    }
}
