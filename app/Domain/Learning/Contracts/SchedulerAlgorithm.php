<?php

declare(strict_types=1);

namespace App\Domain\Learning\Contracts;

use App\Domain\Learning\Enums\SchedulerKind;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;

/**
 * A spaced-repetition algorithm applied to one graded card (docs/13 §2.5).
 *
 * Implementations update the locked `word_repetitions` row in place — state, step, interval,
 * `next_review_at`, algorithm-specific memory columns — mark it with their {@see self::key()}
 * and save it. ReviewSubmissionService picks the implementation through SchedulerResolver and
 * records the key in `training_reviews.scheduler`.
 */
interface SchedulerAlgorithm
{
    public function key(): SchedulerKind;

    /**
     * @param  int  $quality  0..5 (API contract; docs/06 §2)
     */
    public function schedule(WordRepetition $rep, int $quality, CarbonImmutable $now): void;
}
