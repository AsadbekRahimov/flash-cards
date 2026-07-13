<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class ReviewSubmissionService
{
    public function __construct(private readonly SpacedRepetitionEngine $engine) {}

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

            if ($repetition->next_review_at->isAfter($now)) {
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
}
