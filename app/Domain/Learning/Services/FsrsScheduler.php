<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\SchedulerAlgorithm;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Support\FsrsParameters;
use App\Domain\Learning\Support\LearningPolicy;
use App\Domain\Learning\Support\LearningStepMachine;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * FSRS-5 (Free Spaced Repetition Scheduler) for graduated cards — docs/06 §9.
 *
 * The minute steps of `new` / `learning` / `relearning` cards are the SHARED LearningStepMachine
 * (the same `learning_steps`, `relearning_steps`, graduating / easy intervals as SM-2), so the two
 * arms of the A/B differ only in how a `review` card is rescheduled:
 *
 *   rating  G = 1 Again (q 0..2) | 2 Hard (q 3) | 3 Good (q 4) | 4 Easy (q 5)
 *   R(t, S) = (1 + FACTOR · t / S) ^ DECAY                      DECAY = −0.5, FACTOR = 19/81
 *   D0(G)   = w4 − e^(w5 · (G − 1)) + 1
 *   D'      = D + (−w6 · (G − 3)) · (10 − D) / 9                 (linear damping)
 *   D''     = w7 · D0(4) + (1 − w7) · D'                         (mean reversion), clamp 1..10
 *   recall  S' = S · (e^w8 · (11 − D) · S^(−w9) · (e^(w10 · (1 − R)) − 1) · [w15 if Hard] · [w16 if Easy] + 1)
 *   lapse   S' = min(S, w11 · D^(−w12) · ((S + 1)^w13 − 1) · e^(w14 · (1 − R)))
 *   I(r, S) = S / FACTOR · (r ^ (1 / DECAY) − 1), rounded, 1..max_interval_days  (I(0.9, S) = S)
 *
 * S' uses the difficulty BEFORE the update (as fsrs-rs / ts-fsrs do); the same-day weights w17 / w18
 * are unused because same-day repetitions are minute steps here.
 *
 * Memory columns: `stability` (days), `difficulty` (1..10), `scheduled_days` (the interval FSRS
 * assigned, for metrics). They appear when a card graduates under FSRS (S0(G) / D0(G) of the
 * graduating grade for a fresh card; a relearned card keeps its post-lapse stability or, if it never
 * had one, starts from its SM-2 interval) or, for a card scheduled by SM-2 so far, at the first FSRS
 * review: stability = max(1, interval_days), difficulty = clamp(1..10, 5 + (2.5 − EF) · 4 / 1.2).
 * FSRS writes `interval_days` / `next_review_at` / `repetitions` / `lapses` / `is_hard` like SM-2 and
 * never touches `easiness_factor`, so switching a learner back to SM-2 loses nothing (docs/13 §8.3).
 */
final class FsrsScheduler implements SchedulerAlgorithm
{
    public const RATING_AGAIN = 1;

    public const RATING_HARD = 2;

    public const RATING_GOOD = 3;

    public const RATING_EASY = 4;

    public const MIN_STABILITY = 0.01;

    public const MAX_STABILITY = 36500.0;

    public const MIN_DIFFICULTY = 1.0;

    public const MAX_DIFFICULTY = 10.0;

    private readonly LearningStepMachine $steps;

    public function __construct(
        private readonly LearningPolicy $policy,
        private readonly FsrsParameters $params,
    ) {
        $this->steps = new LearningStepMachine($policy);
    }

    public function key(): SchedulerKind
    {
        return SchedulerKind::Fsrs;
    }

    public function schedule(WordRepetition $rep, int $quality, CarbonImmutable $now): void
    {
        SpacedRepetitionEngine::assertQuality($quality);

        if ($rep->state === RepetitionState::Review) {
            $this->reviewGraduated($rep, self::ratingFor($quality), $now);
        } else {
            $wasRelearning = $rep->state === RepetitionState::Relearning;

            if ($this->steps->apply($rep, $quality, $now)) {
                $this->initializeOnGraduation($rep, self::ratingFor($quality), $wasRelearning);
                $rep->scheduled_days = (int) $rep->interval_days;
            }
        }

        $rep->scheduler = SchedulerKind::Fsrs;
        $this->steps->commitAnswer($rep, $quality, $now);

        $rep->save();
    }

    /** API quality 0..5 → FSRS rating 1..4 (docs/06 §9). */
    public static function ratingFor(int $quality): int
    {
        return match (true) {
            $quality < SpacedRepetitionEngine::PASS_QUALITY => self::RATING_AGAIN,
            $quality === SpacedRepetitionEngine::PASS_QUALITY => self::RATING_HARD,
            $quality === SpacedRepetitionEngine::EASY_QUALITY => self::RATING_EASY,
            default => self::RATING_GOOD,
        };
    }

    /** Stability seeded from an SM-2 card: its current interval, at least one day. */
    public static function stabilityFromSm2(int $intervalDays): float
    {
        return (float) max(1, $intervalDays);
    }

    /** Difficulty seeded from an SM-2 easiness factor: EF 2.5 → 5, EF 1.3 → 9, EF 3.7 → 1. */
    public static function difficultyFromEasinessFactor(float $ef): float
    {
        return self::clampDifficulty(5.0 + (2.5 - $ef) * (4.0 / 1.2));
    }

    public function retrievability(float $elapsedDays, float $stability): float
    {
        return (1.0 + FsrsParameters::FACTOR * $elapsedDays / $stability) ** FsrsParameters::DECAY;
    }

    /** The interval (days, unrounded) at which retrievability drops to the desired retention. */
    public function intervalFor(float $stability, ?float $retention = null): float
    {
        $retention ??= $this->params->desiredRetention;

        return $stability / FsrsParameters::FACTOR * ($retention ** (1.0 / FsrsParameters::DECAY) - 1.0);
    }

    public function initialStability(int $rating): float
    {
        return self::clampStability($this->params->w($rating - 1));
    }

    public function initialDifficulty(int $rating): float
    {
        return self::clampDifficulty($this->rawInitialDifficulty($rating));
    }

    public function nextDifficulty(float $difficulty, int $rating): float
    {
        $delta = -$this->params->w(6) * ($rating - self::RATING_GOOD);
        $damped = $difficulty + $delta * (self::MAX_DIFFICULTY - $difficulty) / 9.0;
        $w7 = $this->params->w(7);

        return self::clampDifficulty($w7 * $this->rawInitialDifficulty(self::RATING_EASY) + (1.0 - $w7) * $damped);
    }

    /** Stability after a successful review (Hard / Good / Easy). */
    public function stabilityAfterRecall(float $difficulty, float $stability, float $retrievability, int $rating): float
    {
        $hardPenalty = $rating === self::RATING_HARD ? $this->params->w(15) : 1.0;
        $easyBonus = $rating === self::RATING_EASY ? $this->params->w(16) : 1.0;

        $growth = exp($this->params->w(8))
            * (11.0 - $difficulty)
            * ($stability ** (-$this->params->w(9)))
            * (exp($this->params->w(10) * (1.0 - $retrievability)) - 1.0)
            * $hardPenalty
            * $easyBonus;

        return self::clampStability($stability * ($growth + 1.0));
    }

    /** Stability after a lapse (Again); never above the pre-lapse stability. */
    public function stabilityAfterLapse(float $difficulty, float $stability, float $retrievability): float
    {
        $forget = $this->params->w(11)
            * ($difficulty ** (-$this->params->w(12)))
            * ((($stability + 1.0) ** $this->params->w(13)) - 1.0)
            * exp($this->params->w(14) * (1.0 - $retrievability));

        return self::clampStability(min($forget, $stability));
    }

    private function reviewGraduated(WordRepetition $rep, int $rating, CarbonImmutable $now): void
    {
        $stability = $rep->stability ?? self::stabilityFromSm2((int) $rep->interval_days);
        $difficulty = $rep->difficulty ?? self::difficultyFromEasinessFactor((float) $rep->easiness_factor);
        $retrievability = $this->retrievability($this->elapsedDays($rep, $now), $stability);

        $rep->difficulty = $this->nextDifficulty($difficulty, $rating);

        if ($rating === self::RATING_AGAIN) {
            $rep->stability = $this->stabilityAfterLapse($difficulty, $stability, $retrievability);
            $rep->lapses = (int) $rep->lapses + 1;
            $rep->repetitions = 0;
            $rep->interval_days = 0;
            $rep->scheduled_days = 0;
            $rep->is_hard = true;
            $this->steps->lapse($rep, $now);

            return;
        }

        $newStability = $this->stabilityAfterRecall($difficulty, $stability, $retrievability, $rating);
        $interval = max(1, (int) round($this->intervalFor($newStability)));
        $interval = min($interval, $this->policy->maxIntervalDays);

        $rep->stability = $newStability;
        $rep->state = RepetitionState::Review;
        $rep->learning_step = 0;
        $rep->repetitions = (int) $rep->repetitions + 1;
        $rep->interval_days = $interval;
        $rep->scheduled_days = $interval;
        $rep->next_review_at = $now->addDays($interval);
        $rep->is_hard = false;
    }

    /**
     * First memory estimate of a card that has just graduated under FSRS. A card that already
     * carries one (relearning after an FSRS lapse) keeps it.
     */
    private function initializeOnGraduation(WordRepetition $rep, int $rating, bool $wasRelearning): void
    {
        if ($rep->stability !== null && $rep->difficulty !== null) {
            return;
        }

        if ($wasRelearning) {
            // Lapsed under SM-2, relearned under FSRS: the SM-2 seed (EF already carries the lapse penalty).
            $rep->stability ??= self::stabilityFromSm2((int) $rep->interval_days);
            $rep->difficulty ??= self::difficultyFromEasinessFactor((float) $rep->easiness_factor);

            return;
        }

        $rep->stability ??= $this->initialStability($rating);
        $rep->difficulty ??= $this->initialDifficulty($rating);
    }

    /** Days since the previous answer (fractional, never negative); a card never answered counts its interval. */
    private function elapsedDays(WordRepetition $rep, CarbonImmutable $now): float
    {
        $last = $rep->last_reviewed_at;
        if ($last === null) {
            return (float) max(0, (int) $rep->interval_days);
        }

        return max(0.0, ($now->getTimestamp() - $last->getTimestamp()) / 86400.0);
    }

    private function rawInitialDifficulty(int $rating): float
    {
        return $this->params->w(4) - exp($this->params->w(5) * ($rating - 1)) + 1.0;
    }

    private static function clampStability(float $s): float
    {
        return min(self::MAX_STABILITY, max(self::MIN_STABILITY, $s));
    }

    private static function clampDifficulty(float $d): float
    {
        return min(self::MAX_DIFFICULTY, max(self::MIN_DIFFICULTY, $d));
    }
}
