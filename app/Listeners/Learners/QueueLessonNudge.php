<?php

declare(strict_types=1);

namespace App\Listeners\Learners;

use App\Domain\Learners\Services\LessonNudgeScheduler;
use App\Domain\Learning\Events\LessonWithNewWordsFinished;
use Throwable;

/**
 * Turns {@see LessonWithNewWordsFinished} into a delayed reminder after the lesson ({@see LessonNudgeScheduler}). Synchronous and
 * cheap (one Redis `SET NX` and a queue push); a failure is reported and swallowed: the summary of `/finish` must not fail for a
 * reminder. Auto-discovered (`app/Listeners`).
 */
final class QueueLessonNudge
{
    public function __construct(private readonly LessonNudgeScheduler $scheduler) {}

    public function handle(LessonWithNewWordsFinished $event): void
    {
        try {
            $this->scheduler->schedule($event->learner);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
