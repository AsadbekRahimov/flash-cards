<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Services\SpacedRepetitionEngine;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * The Anki-style minute-step machine shared by every scheduler (docs/06 §3.1–3.4, docs/13 §2.5):
 * `new` / `learning` / `relearning` cards live in minute steps and graduate to day-based intervals;
 * only the day-based part differs between SM-2 and FSRS.
 *
 *   fail  -> step 0 of the current list, is_hard = true
 *   q = 5 -> graduate at once with the easy interval
 *   pass  -> next step, or graduate with the graduating interval after the last one
 *
 * Extracted verbatim from SpacedRepetitionEngine (behaviour unchanged, covered by its tests).
 */
final class LearningStepMachine
{
    public function __construct(private readonly LearningPolicy $policy) {}

    /**
     * Grade a card that is inside minute steps (`new`, `learning` or `relearning`).
     *
     * @return bool whether the card graduated to the day-based schedule
     */
    public function apply(WordRepetition $rep, int $quality, CarbonImmutable $now): bool
    {
        $relearning = $rep->state === RepetitionState::Relearning;
        $state = $relearning ? RepetitionState::Relearning : RepetitionState::Learning;
        $steps = $relearning ? $this->policy->relearningStepsMinutes : $this->policy->learningStepsMinutes;

        if (! SpacedRepetitionEngine::isPass($quality)) {
            $this->restartSteps($rep, $state, $steps, $now);
            $rep->is_hard = true;

            return false;
        }

        if ($quality === SpacedRepetitionEngine::EASY_QUALITY) {
            $this->graduate($rep, $this->policy->easyIntervalDays, $now);

            return true;
        }

        return $this->advanceSteps($rep, $state, $steps, $now);
    }

    /**
     * A graduated card was failed: back to the first relearning step. The caller accounts the
     * lapse itself (`lapses`, `is_hard`, the algorithm's memory columns).
     */
    public function lapse(WordRepetition $rep, CarbonImmutable $now): void
    {
        $this->restartSteps($rep, RepetitionState::Relearning, $this->policy->relearningStepsMinutes, $now);
    }

    /**
     * Enter the day-based schedule. `repetitions = 1` so the next SM-2 pass yields the classic
     * second interval (6 days). `is_hard` is deliberately left as-is: a lapsed card stays "hard"
     * until it passes a review-state answer (used by exam scoring and picker ordering).
     */
    public function graduate(WordRepetition $rep, int $intervalDays, CarbonImmutable $now): void
    {
        $intervalDays = min($intervalDays, $this->policy->maxIntervalDays);

        $rep->state = RepetitionState::Review;
        $rep->learning_step = 0;
        $rep->repetitions = 1;
        $rep->interval_days = $intervalDays;
        $rep->next_review_at = $now->addDays($intervalDays);
    }

    /**
     * Bookkeeping common to every accepted answer: the grade, the time, and the learn-ahead
     * ticket issued by CardPicker is consumed (docs/06 §5.2).
     */
    public function commitAnswer(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        $rep->last_quality = $quality;
        $rep->last_reviewed_at = $now;
        $rep->served_ahead_at = null;
        $rep->served_ahead_token = null;
    }

    /**
     * @param  list<int>  $steps
     */
    private function restartSteps(WordRepetition $rep, RepetitionState $state, array $steps, CarbonImmutable $now): void
    {
        $rep->state = $state;
        $rep->learning_step = 0;
        $rep->repetitions = 0;
        $rep->interval_days = 0;
        $rep->next_review_at = $now->addMinutes($steps[0]);
    }

    /**
     * Move to the next step or graduate after the last one.
     *
     * @param  list<int>  $steps
     * @return bool whether the card graduated
     */
    private function advanceSteps(WordRepetition $rep, RepetitionState $state, array $steps, CarbonImmutable $now): bool
    {
        $nextStep = (int) $rep->learning_step + 1;

        if ($nextStep >= count($steps)) {
            $this->graduate($rep, $this->policy->graduatingIntervalDays, $now);

            return true;
        }

        $rep->state = $state;
        $rep->learning_step = $nextStep;
        $rep->repetitions = 0;
        $rep->interval_days = 0;
        $rep->next_review_at = $now->addMinutes($steps[$nextStep]);

        return false;
    }
}
