<?php

declare(strict_types=1);

namespace App\Domain\Learning\Events;

use App\Domain\Learning\Services\TrainingSessionService;
use App\Models\Learner;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A personal `lesson` session in which the learner met new words was closed by `/finish` ({@see TrainingSessionService::finish()}).
 * Raised AFTER the commit, once per session (only the call that closed it). The bot's reminder after a lesson hangs on it
 * (docs/31 §5б, G1). Listened to synchronously: the learner is the row the request already has.
 */
final class LessonWithNewWordsFinished
{
    use Dispatchable;

    public function __construct(
        public readonly Learner $learner,
        public readonly int $trainingSessionId,
        public readonly int $lessonId,
        public readonly int $newWords,
    ) {}
}
