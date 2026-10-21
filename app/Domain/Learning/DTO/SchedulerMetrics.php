<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\SchedulerKind;

/**
 * One arm of the SRS A/B over the metrics window (docs/13 §2.5 "Метрики", docs/06 §9.6).
 */
final readonly class SchedulerMetrics
{
    public function __construct(
        public SchedulerKind $kind,
        /** grades written by this scheduler in the window */
        public int $reviews,
        /** of them: grades of graduated (review-state) cards that came due */
        public int $dueReviews,
        /** of those: successful (quality >= 3) */
        public int $duePasses,
        /** graduated cards (`repetitions >= 1`) this scheduler answered in the window */
        public int $graduatedWords,
        /** learners of this bucket created between 28 and 7 days ago */
        public int $cohortLearners,
        /** of them: graded at least one card 7+ days after signing up */
        public int $retainedD7,
    ) {}

    public function passRate(): ?float
    {
        return $this->dueReviews === 0 ? null : $this->duePasses / $this->dueReviews;
    }

    public function reviewsPerGraduatedWord(): ?float
    {
        return $this->graduatedWords === 0 ? null : $this->reviews / $this->graduatedWords;
    }

    public function retentionD7(): ?float
    {
        return $this->cohortLearners === 0 ? null : $this->retainedD7 / $this->cohortLearners;
    }
}
