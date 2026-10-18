<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\ReviewOutcome;
use App\Domain\Learning\DTO\TrainingStart;
use App\Domain\Learning\DTO\TrainingStep;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\TrainingSession;

/**
 * Orchestrates the TWA training screen: which card comes next (respecting the
 * per-session review cap) and what the learner sees after grading a card.
 */
final class TrainingFlowService
{
    public function __construct(
        private readonly CardPicker $picker,
        private readonly ReviewSubmissionService $reviews,
        private readonly LearningCache $cache,
    ) {}

    /**
     * @return TrainingStart|null null when the session's lesson no longer exists
     */
    public function start(TrainingSession $session, Learner $learner): ?TrainingStart
    {
        $session->loadMissing('lesson.stage');

        $lesson = $session->lesson;
        if ($lesson === null) {
            return null;
        }

        $totalWords = $this->cache->lessonWordCount((int) $lesson->id);

        return new TrainingStart(
            $session,
            $lesson,
            $totalWords,
            $this->nextStep($session, $learner, $totalWords),
        );
    }

    public function nextStep(TrainingSession $session, Learner $learner, ?int $totalWords = null): TrainingStep
    {
        $lessonId = (int) $session->lesson_id;
        $progress = $this->picker->progress((int) $learner->id, $lessonId, $totalWords);

        $reviewCount = TrainingReview::query()
            ->where('training_session_id', $session->id)
            ->where('learner_id', $learner->id)
            ->count();

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            return new TrainingStep(null, null, $progress);
        }

        $pick = $this->picker->pickNext((int) $learner->id, $lessonId);
        if ($pick === null) {
            return new TrainingStep(null, null, $progress);
        }

        return new TrainingStep(
            $pick['word'],
            $pick['kind'],
            $progress,
            // Only learn-ahead cards carry a ticket (docs/04): a card served ahead earlier and
            // due now is a plain due card; submit() accepts it without a token.
            $pick['ahead'] ? $pick['repetition']->served_ahead_token : null,
        );
    }

    /**
     * @param  string|null  $serveToken  `card.serve_token` of the card being graded (required for learn-ahead cards)
     *
     * @throws ReviewSubmissionException
     */
    public function review(
        TrainingSession $session,
        Learner $learner,
        int $wordId,
        int $quality,
        int $timeSpentMs,
        ?string $serveToken = null,
    ): ReviewOutcome {
        $repetition = $this->reviews->submit($session, $learner, $wordId, $quality, $timeSpentMs, $serveToken);

        return new ReviewOutcome($repetition, $this->nextStep($session, $learner));
    }
}
