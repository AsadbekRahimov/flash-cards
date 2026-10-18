<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\ExamAnswerFeedback;
use App\Domain\Learning\DTO\ExamJoin;
use App\Domain\Learning\DTO\ExamLearnerResult;
use App\Domain\Learning\DTO\ServedExamQuestion;
use App\Domain\Learning\Exceptions\ExamParticipationException;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;

/**
 * Orchestrates the TWA exam screens on top of the personalizer / answer /
 * leaderboard services. Holds no scoring or ordering rules of its own.
 */
final class ExamFlowService
{
    private const LEADERBOARD_TOP = 10;

    public function __construct(
        private readonly ExamQuestionPersonalizer $personalizer,
        private readonly ExamAnswerService $answers,
        private readonly LeaderboardBuilder $leaderboard,
        private readonly LearningCache $cache,
    ) {}

    public function join(ExamSession $session, Learner $learner): ExamJoin
    {
        $config = $session->config;

        return new ExamJoin(
            $session,
            (int) ($config['total_questions'] ?? 0),
            (int) ($config['seconds_per_question'] ?? 0),
            $this->serveQuestion($session, $learner, 0),
        );
    }

    /**
     * Question $index of THIS learner's sequence; starts its server timer on
     * first display. Null when the index is out of range.
     */
    public function serveQuestion(ExamSession $session, Learner $learner, int $index): ?ServedExamQuestion
    {
        $question = $this->personalizer->questionAt($session, $learner, $index);
        if ($question === null) {
            return null;
        }

        $attempt = $this->answers->serve($session, $learner, $index);

        return new ServedExamQuestion($question, $this->answers->secondsLeft($session, $attempt));
    }

    /**
     * @throws ExamParticipationException
     */
    public function answer(
        ExamSession $session,
        Learner $learner,
        int $index,
        int $wordId,
        ?int $selectedIndex,
    ): ExamAnswerFeedback {
        // The correct option is resolved inside the service for THIS learner's
        // personal question/option order — never trusted from the request.
        $answer = $this->answers->submit($session, $learner, $index, $wordId, $selectedIndex);

        $totalScore = (int) ExamAnswer::query()
            ->where('exam_session_id', $session->id)
            ->where('learner_id', $learner->id)
            ->sum('score');

        return new ExamAnswerFeedback(
            $answer['is_correct'],
            $answer['correct_index'],
            $answer['score'],
            $totalScore,
            $index + 1 < $this->personalizer->count($session),
        );
    }

    public function result(ExamSession $session, Learner $learner): ExamLearnerResult
    {
        if (! ExamResult::query()->where('exam_session_id', $session->id)->exists()) {
            $this->leaderboard->build($session);
        }

        $leaderboard = $this->cache->examLeaderboard($session);

        $mine = null;
        foreach ($leaderboard['rows'] as $row) {
            if ($row['learner_id'] === (int) $learner->id) {
                $mine = $row;
                break;
            }
        }

        $top = [];
        foreach (array_slice($leaderboard['rows'], 0, self::LEADERBOARD_TOP) as $row) {
            $top[] = ['rank' => $row['rank'], 'name' => $row['name'], 'score' => $row['score']];
        }

        return new ExamLearnerResult(
            $mine['score'] ?? 0,
            $mine['rank'] ?? null,
            $leaderboard['total_participants'],
            $top,
        );
    }
}
