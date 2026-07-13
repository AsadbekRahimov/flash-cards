<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\ExamParticipationException;
use App\Domain\Learning\Services\ExamAnswerService;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Learning\Services\LearningCache;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Student;
use App\Policies\ExamSessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExamController
{
    public function __construct(
        private readonly ExamSessionPolicy $policy,
        private readonly LeaderboardBuilder $leaderboard,
        private readonly LearningCache $cache,
        private readonly ExamAnswerService $answers,
    ) {}

    public function join(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $config = $session->config;
        $questions = $this->questions($session);

        return response()->json([
            'session_id' => $session->id,
            'ends_at' => $session->ends_at?->toIso8601String(),
            'total_questions' => (int) ($config['total_questions'] ?? 0),
            'seconds_per_question' => (int) ($config['seconds_per_question'] ?? 0),
            'question' => $this->questionPayload($questions, 0, $session, $student),
        ]);
    }

    public function question(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $index = (int) $request->query('index', 0);
        $questions = $this->questions($session);

        if ($index < 0 || $index >= count($questions)) {
            return $this->error(404, 'question_not_found', 'Question index is out of range.');
        }

        return response()->json($this->questionPayload($questions, $index, $session, $student));
    }

    public function answer(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $validated = $request->validate([
            'question_index' => ['required', 'integer', 'min:0'],
            'word_id' => ['required', 'integer'],
            'selected_option_index' => ['nullable', 'integer', 'min:0'],
            // Retained for backwards-compatible client telemetry. Scoring uses server time only.
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ]);

        $questions = $this->questions($session);
        $index = (int) $validated['question_index'];

        if ($index < 0 || $index >= count($questions)) {
            return $this->error(404, 'question_not_found', 'Question index is out of range.');
        }

        $question = $questions[$index];
        if ($question['word_id'] !== (int) $validated['word_id']) {
            return $this->error(422, 'word_mismatch', 'Word does not match the question at this index.');
        }

        $selectedIndex = $validated['selected_option_index'] ?? null;

        try {
            $answer = $this->answers->submit(
                $session,
                $student,
                $index,
                $question,
                $selectedIndex !== null ? (int) $selectedIndex : null,
            );
        } catch (ExamParticipationException $e) {
            return $this->error($e->status, $e->errorCode, $e->getMessage());
        }

        $totalScore = (int) ExamAnswer::query()
            ->where('exam_session_id', $session->id)
            ->where('student_id', $student->id)
            ->sum('score');

        return response()->json([
            'is_correct' => $answer['is_correct'],
            'correct_option' => $question['correct_index'],
            'score_earned' => $answer['score'],
            'total_score' => $totalScore,
            'has_next' => $index + 1 < count($questions),
            // The next question is fetched after feedback so its server timer starts at display time.
            'next_question' => null,
        ]);
    }

    public function result(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: false);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        if (! $this->policy->canViewResult($student, $session)) {
            return $this->error(409, 'exam_still_open', 'Results are available only after the exam is closed.');
        }

        if (! ExamResult::query()->where('exam_session_id', $session->id)->exists()) {
            $this->leaderboard->build($session);
        }

        $leaderboard = $this->cache->examLeaderboard($session);
        $mine = null;
        foreach ($leaderboard['rows'] as $row) {
            if ($row['student_id'] === (int) $student->id) {
                $mine = $row;
                break;
            }
        }

        return response()->json([
            'student_score' => $mine['score'] ?? 0,
            'rank' => $mine['rank'] ?? null,
            'total_participants' => $leaderboard['total_participants'],
            'leaderboard' => collect($leaderboard['rows'])
                ->take(10)
                ->map(fn (array $row): array => [
                    'rank' => $row['rank'],
                    'name' => $row['name'],
                    'score' => $row['score'],
                ])
                ->values(),
        ]);
    }

    /**
     * @return list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}>
     */
    private function questions(ExamSession $session): array
    {
        /** @var list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}> $questions */
        $questions = $session->config['questions'] ?? [];

        return $questions;
    }

    /**
     * @param  list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}>  $questions
     * @return array{question_index:int, word_id:int, word:string, options:list<string>, seconds_left:int}|null
     */
    private function questionPayload(
        array $questions,
        int $index,
        ExamSession $session,
        Student $student,
    ): ?array {
        if ($index < 0 || $index >= count($questions)) {
            return null;
        }

        $question = $questions[$index];
        $attempt = $this->answers->serve($session, $student, $index);

        return [
            'question_index' => $index,
            'word_id' => $question['word_id'],
            'word' => $question['word'],
            'options' => $question['options'],
            'seconds_left' => $this->answers->secondsLeft($session, $attempt),
        ];
    }

    /**
     * @return array{0: Student, 1: ExamSession}|array{0: null, 1: JsonResponse}
     */
    private function resolve(Request $request, int $sessionId, bool $requireOpen): array
    {
        /** @var Student $student */
        $student = $request->attributes->get('student');

        /** @var ExamSession|null $session */
        $session = ExamSession::query()->find($sessionId);

        if ($session === null) {
            return [null, $this->error(404, 'session_not_found', 'Exam session not found.')];
        }

        if (! $this->policy->canAccess($student, $session)) {
            return [null, $this->error(403, 'forbidden', 'You cannot access this exam session.')];
        }

        if ($requireOpen && (
            $session->status !== 'open'
            || ($session->ends_at !== null && ! $session->ends_at->isFuture())
        )) {
            return [null, $this->error(410, 'session_closed', 'This exam session is closed.')];
        }

        return [$student, $session];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function unauthorizedStudent(): JsonResponse
    {
        return $this->error(401, 'student_not_found', 'Unauthorized');
    }
}
