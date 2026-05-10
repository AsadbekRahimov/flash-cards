<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Learning\Services\LearningCache;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\WordRepetition;
use App\Policies\ExamSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TWA exam endpoints.
 *
 *   POST /api/twa/exam/sessions/{id}/join
 *   GET  /api/twa/exam/sessions/{id}/question?index=N
 *   POST /api/twa/exam/sessions/{id}/answer
 *   GET  /api/twa/exam/sessions/{id}/result
 */
final class ExamController
{
    /** Points awarded for a correct answer at t=0 (faster = more). */
    private const MAX_POINTS_PER_QUESTION = 20;

    public function __construct(
        private readonly ExamSessionPolicy $policy,
        private readonly LeaderboardBuilder $leaderboard,
        private readonly LearningCache $cache,
    ) {}

    public function join(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $config = $session->config ?? [];
        $questions = $this->questions($session);

        return response()->json([
            'session_id' => $session->id,
            'ends_at' => $session->ends_at?->toIso8601String(),
            'total_questions' => (int) ($config['total_questions'] ?? 0),
            'seconds_per_question' => (int) ($config['seconds_per_question'] ?? 0),
            'question' => $this->questionPayload($questions, 0, $session),
        ]);
    }

    public function question(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $index = (int) $request->query('index', 0);

        $questions = $this->questions($session);
        if ($index < 0 || $index >= count($questions)) {
            return $this->error(404, 'question_not_found', 'Question index is out of range.');
        }

        return response()->json($this->questionPayload($questions, $index, $session));
    }

    public function answer(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $validated = $request->validate([
            'question_index' => ['required', 'integer', 'min:0'],
            'word_id' => ['required', 'integer'],
            'selected_option_index' => ['nullable', 'integer', 'min:0'],
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ]);

        $questions = $this->questions($session);
        $index = (int) $validated['question_index'];

        if ($index < 0 || $index >= count($questions)) {
            return $this->error(404, 'question_not_found', 'Question index is out of range.');
        }

        $q = $questions[$index];
        if ((int) $q['word_id'] !== (int) $validated['word_id']) {
            return $this->error(422, 'word_mismatch', 'Word does not match the question at this index.');
        }

        $existing = ExamAnswer::query()
            ->where('exam_session_id', $session->id)
            ->where('student_id', $student->id)
            ->where('word_id', $validated['word_id'])
            ->exists();

        if ($existing) {
            return $this->error(409, 'already_answered', 'You have already answered this question.');
        }

        $selectedIndex = $validated['selected_option_index'] ?? null;
        $options = $q['options'] ?? [];
        $isCorrect = $selectedIndex !== null
            && isset($options[$selectedIndex])
            && (int) $selectedIndex === (int) $q['correct_index'];

        $isHard = (bool) WordRepetition::query()
            ->where('student_id', $student->id)
            ->where('word_id', $validated['word_id'])
            ->value('is_hard');

        $score = $this->computeScore($isCorrect, (int) $validated['time_spent_ms'], $session, $isHard);

        ExamAnswer::query()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'word_id' => $validated['word_id'],
            'selected_translation' => $selectedIndex !== null ? ($options[$selectedIndex] ?? null) : null,
            'is_correct' => $isCorrect,
            'score' => $score,
            'time_spent_ms' => (int) $validated['time_spent_ms'],
            'answered_at' => now(),
        ]);

        $totalScore = (int) ExamAnswer::query()
            ->where('exam_session_id', $session->id)
            ->where('student_id', $student->id)
            ->sum('score');

        $totalQuestions = count($questions);

        return response()->json([
            'is_correct' => $isCorrect,
            'correct_option' => (int) $q['correct_index'],
            'score_earned' => $score,
            'total_score' => $totalScore,
            'has_next' => $index + 1 < $totalQuestions,
            'next_question' => $this->questionPayload($questions, $index + 1, $session),
        ]);
    }

    public function result(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId, requireOpen: false);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        if (! $this->policy->canViewResult($student, $session)) {
            return $this->error(409, 'exam_still_open', 'Results are available only after the exam is closed.');
        }

        // Lazy-build results if the scheduler hasn't run yet.
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

    private function computeScore(bool $isCorrect, int $timeSpentMs, ExamSession $session, bool $isHard = false): int
    {
        if (! $isCorrect) {
            return 0;
        }

        $perQuestion = (int) ($session->config['seconds_per_question'] ?? 10);
        $totalMs = $perQuestion * 1000;
        $remaining = max(0.0, 1.0 - ($timeSpentMs / max(1, $totalMs)));

        // Minimum 5 points for a correct answer even if time ran out.
        $base = max(5, (int) round(self::MAX_POINTS_PER_QUESTION * (0.25 + 0.75 * $remaining)));

        // PRD FR-TWA-03: hard words reward 1.5× to incentivise reviewing difficult vocabulary.
        return $isHard ? (int) round($base * 1.5) : $base;
    }

    private function questionSecondsLeft(ExamSession $session): int
    {
        if ($session->ends_at === null) {
            return 0;
        }
        $left = CarbonImmutable::now()->diffInSeconds($session->ends_at, false);

        return max(0, (int) $left);
    }

    /**
     * @return list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}>
     */
    private function questions(ExamSession $session): array
    {
        /** @var list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}> $q */
        $q = $session->config['questions'] ?? [];

        return $q;
    }

    /**
     * @param  list<array{word_id:int, word:string, correct_translation:string, correct_index:int, options:list<string>}>  $questions
     * @return array{question_index:int, word_id:int, word:string, options:list<string>, seconds_left:int}|null
     */
    private function questionPayload(array $questions, int $index, ExamSession $session): ?array
    {
        if ($index < 0 || $index >= count($questions)) {
            return null;
        }

        $q = $questions[$index];

        return [
            'question_index' => $index,
            'word_id' => (int) $q['word_id'],
            'word' => (string) $q['word'],
            'options' => array_values($q['options']),
            'seconds_left' => $this->questionSecondsLeft($session),
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

        if ($requireOpen && $session->status !== 'open') {
            return [null, $this->error(410, 'session_closed', 'This exam session is closed.')];
        }

        return [$student, $session];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
