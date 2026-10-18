<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Support\LearningPolicy;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomically applies a student's grade to a card inside an open session.
 *
 * Double-submit protection: a card may be graded only if it is due
 * (`next_review_at <= now`) or if CardPicker served it early (learn-ahead)
 * and that ticket (`served_ahead_at`) has not been consumed yet. Every
 * accepted review moves `next_review_at` into the future and clears the
 * ticket, so replaying the same request is rejected with 409 `card_not_due`.
 */
final class ReviewSubmissionService
{
    public function __construct(
        private readonly SpacedRepetitionEngine $engine,
        private readonly LearningPolicy $policy,
    ) {}

    public function submit(
        TrainingSession $session,
        Student $student,
        int $wordId,
        int $quality,
        int $timeSpentMs,
        ?CarbonImmutable $now = null,
    ): WordRepetition {
        $now ??= CarbonImmutable::now();

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        return $db->transaction(function () use (
            $session,
            $student,
            $wordId,
            $quality,
            $timeSpentMs,
            $now,
        ): WordRepetition {
            /** @var TrainingSession|null $lockedSession */
            $lockedSession = TrainingSession::query()
                ->lockForUpdate()
                ->find($session->id);

            if ($lockedSession === null || $lockedSession->status !== 'open') {
                throw new ReviewSubmissionException(
                    410,
                    'session_closed',
                    'This training session is closed.',
                );
            }

            /** @var WordRepetition|null $repetition */
            $repetition = WordRepetition::query()
                ->where('student_id', $student->id)
                ->where('word_id', $wordId)
                ->with('word')
                ->lockForUpdate()
                ->first();

            if ($repetition === null || $repetition->word === null) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_started',
                    'Card was never served to this student.',
                );
            }

            if ((int) $repetition->word->lesson_id !== (int) $lockedSession->lesson_id) {
                throw new ReviewSubmissionException(
                    422,
                    'wrong_lesson',
                    'Word does not belong to this session lesson.',
                );
            }

            if (! $this->isAcceptable($repetition, $now)) {
                throw new ReviewSubmissionException(
                    409,
                    'card_not_due',
                    'This card was already reviewed and is not due yet.',
                );
            }

            $this->engine->applyReview($repetition, $quality, $now);

            TrainingReview::query()->create([
                'training_session_id' => $session->id,
                'student_id' => $student->id,
                'word_id' => $wordId,
                'quality' => $quality,
                'time_spent_ms' => $timeSpentMs,
                'created_at' => $now,
            ]);

            return $repetition;
        });
    }

    private function isAcceptable(WordRepetition $repetition, CarbonImmutable $now): bool
    {
        if (! $repetition->next_review_at->isAfter($now)) {
            return true;
        }

        if ($repetition->served_ahead_at === null || ! $repetition->state->isIntraSession()) {
            return false;
        }

        // Learn-ahead ticket is valid only inside the configured window, and only
        // if it was issued after the previous answer (the engine clears it on review).
        return ! $repetition->next_review_at->isAfter($now->addMinutes($this->policy->learnAheadMinutes));
    }
}
