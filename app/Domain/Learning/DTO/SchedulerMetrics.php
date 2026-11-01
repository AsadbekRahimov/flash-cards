<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Domain\Learning\Enums\SchedulerKind;

/**
 * One arm of the SRS A/B over the metrics window (docs/13 §2.5 "Метрики", docs/06 §9.6).
 *
 * The admin widget caches it as an array ({@see toArray()}): a cache holds scalars only, so the cache never
 * unserializes an object (`cache.serializable_classes` is `false`) and a deploy never meets a stale one.
 *
 * @phpstan-type SchedulerMetricsArray array{kind:string, reviews:int, due_reviews:int, due_passes:int, graduated_words:int, cohort_learners:int, retained_d7:int}
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

    /** @return SchedulerMetricsArray */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'reviews' => $this->reviews,
            'due_reviews' => $this->dueReviews,
            'due_passes' => $this->duePasses,
            'graduated_words' => $this->graduatedWords,
            'cohort_learners' => $this->cohortLearners,
            'retained_d7' => $this->retainedD7,
        ];
    }

    /** @param  SchedulerMetricsArray  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            SchedulerKind::from($data['kind']),
            $data['reviews'],
            $data['due_reviews'],
            $data['due_passes'],
            $data['graduated_words'],
            $data['cohort_learners'],
            $data['retained_d7'],
        );
    }

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
