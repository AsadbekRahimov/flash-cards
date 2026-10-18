<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * SM-2 (SuperMemo-2) with Anki-style learning steps.
 *
 * Reference: docs/06_SPACED_REPETITION.md
 *
 * Quality scale (API contract, 0..5):
 *   q <  3  -> fail ("Again")
 *   q == 3  -> pass with difficulty ("Hard",  EF -0.14)
 *   q == 4  -> pass ("Good", EF unchanged)
 *   q == 5  -> pass with ease ("Easy", EF +0.10; in (re)learning graduates immediately)
 *
 * State machine:
 *   new/learning  : minute steps (policy->learningStepsMinutes).
 *                   fail  -> step 0, is_hard = true
 *                   pass  -> next step, or graduate (interval = 1 day; q=5 -> easy interval)
 *                   EF is NOT touched while learning.
 *   review        : classic SM-2.
 *                   EF' = max(1.30, EF + (0.1 - (5 - q) * (0.08 + (5 - q) * 0.02)))
 *                   fail  -> lapses++, repetitions = 0, state = relearning (step 0), is_hard = true
 *                   pass  -> repetitions++, interval = 1 | 6 | round(interval * EF'), is_hard = false
 *   relearning    : minute steps (policy->relearningStepsMinutes).
 *                   fail  -> step 0 (EF already penalised at the lapse)
 *                   pass  -> next step, or graduate (repetitions = 1, interval = 1 day)
 *                   is_hard stays true until the card passes a review-state answer.
 */
final class SpacedRepetitionEngine
{
    public const MIN_EF = 1.30;

    public const DEFAULT_EF = 2.50;

    public const MIN_QUALITY = 0;

    public const MAX_QUALITY = 5;

    /** Smallest quality that counts as a successful recall. */
    public const PASS_QUALITY = 3;

    /** Quality that skips remaining (re)learning steps. */
    public const EASY_QUALITY = 5;

    public function __construct(private readonly LearningPolicy $policy) {}

    public function applyReview(
        WordRepetition $rep,
        int $quality,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        $this->assertQuality($quality);

        $now ??= CarbonImmutable::now();

        match ($rep->state) {
            RepetitionState::New, RepetitionState::Learning => $this->reviewLearning($rep, $quality, $now),
            RepetitionState::Relearning => $this->reviewRelearning($rep, $quality, $now),
            RepetitionState::Review => $this->reviewGraduated($rep, $quality, $now),
        };

        $rep->last_quality = $quality;
        $rep->last_reviewed_at = $now;
        // Any answer consumes the learn-ahead ticket issued by CardPicker.
        $rep->served_ahead_at = null;

        $rep->save();

        return $rep;
    }

    public function newEasinessFactor(float $ef, int $quality): float
    {
        $this->assertQuality($quality);

        $delta = 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02);

        return max(self::MIN_EF, $ef + $delta);
    }

    public static function isPass(int $quality): bool
    {
        return $quality >= self::PASS_QUALITY;
    }

    private function reviewLearning(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        $steps = $this->policy->learningStepsMinutes;

        if (! self::isPass($quality)) {
            $this->restartSteps($rep, RepetitionState::Learning, $steps, $now);
            $rep->is_hard = true;

            return;
        }

        if ($quality === self::EASY_QUALITY) {
            $this->graduate($rep, $this->policy->easyIntervalDays, $now);

            return;
        }

        $this->advanceSteps($rep, RepetitionState::Learning, $steps, $now);
    }

    private function reviewRelearning(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        $steps = $this->policy->relearningStepsMinutes;

        if (! self::isPass($quality)) {
            $this->restartSteps($rep, RepetitionState::Relearning, $steps, $now);
            $rep->is_hard = true;

            return;
        }

        if ($quality === self::EASY_QUALITY) {
            $this->graduate($rep, $this->policy->easyIntervalDays, $now);

            return;
        }

        $this->advanceSteps($rep, RepetitionState::Relearning, $steps, $now);
    }

    private function reviewGraduated(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        $previousRepetitions = (int) $rep->repetitions;
        $previousInterval = (int) $rep->interval_days;

        $ef = $this->newEasinessFactor((float) $rep->easiness_factor, $quality);
        $rep->easiness_factor = round($ef, 2);

        if (! self::isPass($quality)) {
            $rep->lapses = (int) $rep->lapses + 1;
            $rep->repetitions = 0;
            $rep->interval_days = 0;
            $rep->is_hard = true;
            $this->restartSteps($rep, RepetitionState::Relearning, $this->policy->relearningStepsMinutes, $now);

            return;
        }

        $interval = match (true) {
            $previousRepetitions === 0 => 1,
            $previousRepetitions === 1 => 6,
            default => max(1, (int) round($previousInterval * $ef)),
        };

        $rep->state = RepetitionState::Review;
        $rep->learning_step = 0;
        $rep->repetitions = $previousRepetitions + 1;
        $rep->interval_days = $interval;
        $rep->next_review_at = $now->addDays($interval);
        $rep->is_hard = false;
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
     */
    private function advanceSteps(WordRepetition $rep, RepetitionState $state, array $steps, CarbonImmutable $now): void
    {
        $nextStep = (int) $rep->learning_step + 1;

        if ($nextStep >= count($steps)) {
            $this->graduate($rep, $this->policy->graduatingIntervalDays, $now);

            return;
        }

        $rep->state = $state;
        $rep->learning_step = $nextStep;
        $rep->repetitions = 0;
        $rep->interval_days = 0;
        $rep->next_review_at = $now->addMinutes($steps[$nextStep]);
    }

    /**
     * Enter the day-based SM-2 schedule. `repetitions = 1` so the next pass
     * yields the classic second interval (6 days). `is_hard` is deliberately
     * left as-is: a lapsed card stays "hard" until it passes a review-state
     * answer (used by exam scoring and picker ordering).
     */
    private function graduate(WordRepetition $rep, int $intervalDays, CarbonImmutable $now): void
    {
        $rep->state = RepetitionState::Review;
        $rep->learning_step = 0;
        $rep->repetitions = 1;
        $rep->interval_days = $intervalDays;
        $rep->next_review_at = $now->addDays($intervalDays);
    }

    private function assertQuality(int $q): void
    {
        if ($q < self::MIN_QUALITY || $q > self::MAX_QUALITY) {
            throw new InvalidArgumentException("Quality must be 0..5, got {$q}");
        }
    }
}
