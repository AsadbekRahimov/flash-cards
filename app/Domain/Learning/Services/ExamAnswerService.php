<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Exceptions\ExamParticipationException;
use App\Models\ExamAnswer;
use App\Models\ExamQuestionAttempt;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ExamAnswerService
{
    private const MAX_POINTS_PER_QUESTION = 20;

    public function __construct(private readonly ExamQuestionPersonalizer $personalizer) {}

    public function serve(
        ExamSession $session,
        Learner $learner,
        int $questionIndex,
        ?CarbonImmutable $now = null,
    ): ExamQuestionAttempt {
        $now ??= CarbonImmutable::now();

        try {
            /** @var ExamQuestionAttempt $attempt */
            $attempt = ExamQuestionAttempt::query()->firstOrCreate([
                'exam_session_id' => $session->id,
                'learner_id' => $learner->id,
                'question_index' => $questionIndex,
            ], [
                'served_at' => $now,
            ]);

            return $attempt;
        } catch (UniqueConstraintViolationException) {
            /** @var ExamQuestionAttempt $attempt */
            $attempt = ExamQuestionAttempt::query()
                ->where('exam_session_id', $session->id)
                ->where('learner_id', $learner->id)
                ->where('question_index', $questionIndex)
                ->firstOrFail();

            return $attempt;
        }
    }

    public function secondsLeft(ExamSession $session, ExamQuestionAttempt $attempt): int
    {
        $now = CarbonImmutable::now();
        $questionDeadline = $attempt->served_at->addSeconds($this->secondsPerQuestion($session));
        $deadline = $session->ends_at !== null && $session->ends_at->isBefore($questionDeadline)
            ? CarbonImmutable::instance($session->ends_at)
            : $questionDeadline;

        return max(0, (int) ceil($now->diffInMilliseconds($deadline, false) / 1000));
    }

    /**
     * Records the answer of $learner to the question at $questionIndex of
     * THEIR personal sequence. The correct option is recomputed here from the
     * learner's own permutation, so an index copied from a classmate's screen
     * is graded against this learner's option order, not the classmate's.
     *
     * @return array{is_correct:bool, score:int, time_spent_ms:int, correct_index:int, word_id:int}
     *
     * @throws ExamParticipationException 404 question_not_found, 422 word_mismatch,
     *                                    409 question_not_served / already_answered, 410 session_closed
     */
    public function submit(
        ExamSession $session,
        Learner $learner,
        int $questionIndex,
        int $wordId,
        ?int $selectedIndex,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();

        $question = $this->personalizer->questionAt($session, $learner, $questionIndex);
        if ($question === null) {
            throw new ExamParticipationException(404, 'question_not_found', 'Question index is out of range.');
        }

        if ($question['word_id'] !== $wordId) {
            throw new ExamParticipationException(
                422,
                'word_mismatch',
                'Word does not match the question at this index.',
            );
        }

        /** @var ConnectionInterface $db */
        $db = DB::connection();

        try {
            return $db->transaction(function () use (
                $session,
                $learner,
                $questionIndex,
                $question,
                $selectedIndex,
                $now,
            ): array {
                /** @var ExamSession|null $lockedSession */
                $lockedSession = ExamSession::query()
                    ->lockForUpdate()
                    ->find($session->id);

                if (
                    $lockedSession === null
                    || $lockedSession->status !== 'open'
                    || ($lockedSession->ends_at !== null && ! $lockedSession->ends_at->isAfter($now))
                ) {
                    throw new ExamParticipationException(410, 'session_closed', 'This exam session has ended.');
                }

                /** @var ExamQuestionAttempt|null $attempt */
                $attempt = ExamQuestionAttempt::query()
                    ->where('exam_session_id', $session->id)
                    ->where('learner_id', $learner->id)
                    ->where('question_index', $questionIndex)
                    ->lockForUpdate()
                    ->first();

                if ($attempt === null) {
                    throw new ExamParticipationException(
                        409,
                        'question_not_served',
                        'Load this question before submitting an answer.',
                    );
                }

                if ($attempt->answered_at !== null) {
                    throw new ExamParticipationException(
                        409,
                        'already_answered',
                        'You have already answered this question.',
                    );
                }

                $timeSpentMs = max(0, (int) $attempt->served_at->diffInMilliseconds($now, false));
                $withinQuestionTime = $timeSpentMs <= $this->secondsPerQuestion($lockedSession) * 1000;
                $options = $question['options'];
                $isCorrect = $withinQuestionTime
                    && $selectedIndex !== null
                    && isset($options[$selectedIndex])
                    && $selectedIndex === $question['correct_index'];

                $isHard = (bool) WordRepetition::query()
                    ->where('learner_id', $learner->id)
                    ->where('word_id', $question['word_id'])
                    ->value('is_hard');

                $score = $this->computeScore($isCorrect, $timeSpentMs, $lockedSession, $isHard);

                ExamAnswer::query()->create([
                    'exam_session_id' => $session->id,
                    'learner_id' => $learner->id,
                    'word_id' => $question['word_id'],
                    'selected_translation' => $selectedIndex !== null ? ($options[$selectedIndex] ?? null) : null,
                    'is_correct' => $isCorrect,
                    'score' => $score,
                    'time_spent_ms' => $timeSpentMs,
                    'answered_at' => $now,
                ]);

                $attempt->answered_at = $now;
                $attempt->save();

                return [
                    'is_correct' => $isCorrect,
                    'score' => $score,
                    'time_spent_ms' => $timeSpentMs,
                    'correct_index' => $question['correct_index'],
                    'word_id' => $question['word_id'],
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw new ExamParticipationException(
                409,
                'already_answered',
                'You have already answered this question.',
            );
        }
    }

    private function computeScore(bool $isCorrect, int $timeSpentMs, ExamSession $session, bool $isHard): int
    {
        if (! $isCorrect) {
            return 0;
        }

        $totalMs = $this->secondsPerQuestion($session) * 1000;
        $remaining = max(0.0, 1.0 - ($timeSpentMs / max(1, $totalMs)));
        $base = max(5, (int) round(self::MAX_POINTS_PER_QUESTION * (0.25 + 0.75 * $remaining)));

        return $isHard ? (int) round($base * 1.5) : $base;
    }

    private function secondsPerQuestion(ExamSession $session): int
    {
        return max(1, (int) ($session->config['seconds_per_question'] ?? 10));
    }
}
