<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * SM-2 (SuperMemo-2) spaced repetition algorithm.
 *
 * Reference: docs/06_SPACED_REPETITION.md
 *
 *   EF' = max(1.30, EF + (0.1 - (5 - q) * (0.08 + (5 - q) * 0.02)))
 *
 *   if q < 3:
 *       repetitions = 0, interval = 1, is_hard = true
 *   else:
 *       repetitions += 1
 *       interval = match on previous repetitions:
 *           0 -> 1
 *           1 -> 6
 *           _ -> round(interval_prev * EF')
 *       is_hard = (q == 2)   // unreachable here since q >= 3, kept as doc
 */
final class SpacedRepetitionEngine
{
    public const MIN_EF     = 1.30;
    public const DEFAULT_EF = 2.50;

    public const MIN_QUALITY = 0;
    public const MAX_QUALITY = 5;

    public function applyReview(
        WordRepetition $rep,
        int $quality,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        $this->assertQuality($quality);

        $now ??= CarbonImmutable::now();

        $previousRepetitions = (int) $rep->repetitions;
        $previousInterval    = (int) $rep->interval_days;
        $previousEf          = (float) $rep->easiness_factor;

        $ef = $this->newEasinessFactor($previousEf, $quality);

        if ($quality < 3) {
            $repetitions = 0;
            $interval    = 1;
            $isHard      = true;
        } else {
            $repetitions = $previousRepetitions + 1;
            $interval    = match (true) {
                $previousRepetitions === 0 => 1,
                $previousRepetitions === 1 => 6,
                default                    => max(1, (int) round($previousInterval * $ef)),
            };
            $isHard = false;
        }

        $rep->easiness_factor  = round($ef, 2);
        $rep->repetitions      = $repetitions;
        $rep->interval_days    = $interval;
        $rep->last_quality     = $quality;
        $rep->last_reviewed_at = $now;
        $rep->next_review_at   = $now->addDays($interval);
        $rep->is_hard          = $isHard;

        $rep->save();

        return $rep;
    }

    public function newEasinessFactor(float $ef, int $quality): float
    {
        $this->assertQuality($quality);

        $delta = 0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02);

        return max(self::MIN_EF, $ef + $delta);
    }

    private function assertQuality(int $q): void
    {
        if ($q < self::MIN_QUALITY || $q > self::MAX_QUALITY) {
            throw new InvalidArgumentException("Quality must be 0..5, got {$q}");
        }
    }
}
