<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Contracts\SchedulerAlgorithm;
use App\Domain\Learning\Enums\SchedulerKind;
use App\Domain\Learning\Support\FsrsParameters;
use App\Models\Learner;

/**
 * Which algorithm schedules a learner's cards (docs/13 §2.5, §8.3):
 * `learners.scheduler`, or the platform default `config('learning.algorithm')` when NULL.
 *
 * The A/B bucket is assigned ONCE, when LearnerRegistrar creates the learner:
 * `crc32(telegram_user_id) % 100 < fsrs.rollout_percent` → FSRS, otherwise SM-2. The hash is
 * stable, so the same person always lands in the same bucket for a given percentage, and raising
 * the percentage only adds new learners; an existing learner moves only by an explicit admin switch.
 */
final class SchedulerResolver
{
    public function __construct(
        private readonly Sm2Scheduler $sm2,
        private readonly FsrsScheduler $fsrs,
        private readonly FsrsParameters $params,
    ) {}

    public function for(Learner $learner): SchedulerAlgorithm
    {
        return $this->algorithm($this->kindFor($learner));
    }

    public function kindFor(Learner $learner): SchedulerKind
    {
        return $learner->scheduler ?? $this->defaultKind();
    }

    public function algorithm(SchedulerKind $kind): SchedulerAlgorithm
    {
        return match ($kind) {
            SchedulerKind::Sm2 => $this->sm2,
            SchedulerKind::Fsrs => $this->fsrs,
        };
    }

    /** `config('learning.algorithm')`; an unknown value falls back to SM-2. */
    public function defaultKind(): SchedulerKind
    {
        return SchedulerKind::tryFrom((string) config('learning.algorithm', SchedulerKind::Sm2->value)) ?? SchedulerKind::Sm2;
    }

    /** The bucket a NEW learner with this Telegram id gets at the current rollout percentage. */
    public function bucketFor(int $telegramUserId): SchedulerKind
    {
        return crc32((string) $telegramUserId) % 100 < $this->params->rolloutPercent
            ? SchedulerKind::Fsrs
            : SchedulerKind::Sm2;
    }
}
