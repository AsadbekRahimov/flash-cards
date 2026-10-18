<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\TrainingSession;

/**
 * What a learner did inside one training session (TrainingSessionService::finish).
 * Daily goal and streak are added by TodayService (task 1.3b) on top of this.
 */
final readonly class SessionSummary
{
    /**
     * @param  int  $reviewed  accepted grades (training_reviews rows)
     * @param  int  $correct  grades with quality >= 3
     * @param  int  $newWords  distinct words first served during this session
     * @param  bool  $wasOpen  false when the session had already been closed before this call (idempotent finish)
     */
    public function __construct(
        public TrainingSession $session,
        public int $reviewed,
        public int $correct,
        public int $newWords,
        public bool $wasOpen,
    ) {}
}
