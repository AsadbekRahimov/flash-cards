<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\LearningStepMachine;
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
 *   new/learning  : minute steps (policy->learningStepsMinutes) — LearningStepMachine.
 *                   fail  -> step 0, is_hard = true
 *                   pass  -> next step, or graduate (interval = 1 day; q=5 -> easy interval)
 *                   EF is NOT touched while learning.
 *   review        : classic SM-2.
 *                   EF' = max(1.30, EF + (0.1 - (5 - q) * (0.08 + (5 - q) * 0.02)))
 *                   fail  -> lapses++, repetitions = 0, state = relearning (step 0), is_hard = true
 *                   pass  -> repetitions++, interval = 1 | 6 | round(interval * EF'), is_hard = false
 *   Every scheduled interval is capped at policy->maxIntervalDays (smallint column).
 *   relearning    : minute steps (policy->relearningStepsMinutes).
 *                   fail  -> step 0 (EF already penalised at the lapse)
 *                   pass  -> next step, or graduate (repetitions = 1, interval = 1 day)
 *                   is_hard stays true until the card passes a review-state answer.
 *
 * The FSRS alternative (docs/06 §9) shares the step machine and replaces only the review branch.
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

    private readonly LearningStepMachine $steps;

    public function __construct(private readonly LearningPolicy $policy)
    {
        $this->steps = new LearningStepMachine($policy);
    }

    public function applyReview(
        WordRepetition $rep,
        int $quality,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        self::assertQuality($quality);

        $now ??= CarbonImmutable::now();

        match ($rep->state) {
            RepetitionState::New, RepetitionState::Learning, RepetitionState::Relearning => $this->steps->apply($rep, $quality, $now),
            RepetitionState::Review => $this->reviewGraduated($rep, $quality, $now),
        };

        $rep->scheduler = SchedulerKind::Sm2;
        $this->steps->commitAnswer($rep, $quality, $now);

        $rep->save();

        return $rep;
    }

    public function newEasinessFactor(float $ef, int $quality): float
    {
        self::assertQuality($quality);

        $delta = 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02);

        return max(self::MIN_EF, $ef + $delta);
    }

    public static function isPass(int $quality): bool
    {
        return $quality >= self::PASS_QUALITY;
    }

    public static function assertQuality(int $q): void
    {
        if ($q < self::MIN_QUALITY || $q > self::MAX_QUALITY) {
            throw new InvalidArgumentException("Quality must be 0..5, got {$q}");
        }
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
            $this->steps->lapse($rep, $now);

            return;
        }

        $interval = match (true) {
            $previousRepetitions === 0 => 1,
            $previousRepetitions === 1 => 6,
            default => max(1, (int) round($previousInterval * $ef)),
        };
        $interval = min($interval, $this->policy->maxIntervalDays);

        $rep->state = RepetitionState::Review;
        $rep->learning_step = 0;
        $rep->repetitions = $previousRepetitions + 1;
        $rep->interval_days = $interval;
        $rep->next_review_at = $now->addDays($interval);
        $rep->is_hard = false;
    }
}
