<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\LearningCache;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Policies\TrainingSessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrainingController
{
    public function __construct(
        private readonly CardPicker $picker,
        private readonly ReviewSubmissionService $reviews,
        private readonly LearningCache $cache,
        private readonly TrainingSessionPolicy $policy,
    ) {}

    public function start(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $session->loadMissing('lesson.stage');

        $lesson = $session->lesson;
        if ($lesson === null) {
            return response()->json([
                'error' => ['code' => 'lesson_not_found', 'message' => 'Training lesson not found.'],
            ], 404);
        }

        $totalWords = $this->cache->lessonWordCount((int) $lesson->id);
        $next = $this->nextCardPayload($session, $student, (int) $lesson->id, $totalWords);

        return response()->json([
            'session_id' => $session->id,
            'lesson' => [
                'stage' => $lesson->stage?->number,
                'lesson' => $lesson->number,
                'title' => $lesson->title,
            ],
            'total_words' => $totalWords,
            'card' => $next['card'],
            'progress' => $next['progress'],
        ]);
    }

    public function next(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        return response()->json($this->nextCardPayload($session, $student, (int) $session->lesson_id));
    }

    public function review(Request $request, int $sessionId): JsonResponse
    {
        [$student, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $validated = $request->validate([
            'word_id' => ['required', 'integer'],
            'quality' => ['required', 'integer', 'min:0', 'max:5'],
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ]);

        try {
            $rep = $this->reviews->submit(
                $session,
                $student,
                (int) $validated['word_id'],
                (int) $validated['quality'],
                (int) $validated['time_spent_ms'],
            );
        } catch (ReviewSubmissionException $e) {
            return response()->json([
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            ], $e->status);
        }

        return response()->json([
            'next_review_at' => $rep->next_review_at->toIso8601String(),
            'new_interval_days' => (int) $rep->interval_days,
            'easiness_factor' => (float) $rep->easiness_factor,
            ...$this->nextCardPayload($session, $student, (int) $session->lesson_id),
        ]);
    }

    /**
     * @return array{
     *   card: array{word_id:int, word:string, translation:string, example:string|null, transcription:string|null, card_kind:'due'|'new'|'learning'}|null,
     *   progress: array{done:int, total:int}
     * }
     */
    private function nextCardPayload(
        TrainingSession $session,
        Student $student,
        int $lessonId,
        ?int $totalWords = null,
    ): array {
        $progress = $this->picker->progress((int) $student->id, $lessonId, $totalWords);
        $reviewCount = TrainingReview::query()
            ->where('training_session_id', $session->id)
            ->where('student_id', $student->id)
            ->count();

        if ($reviewCount >= max(1, (int) config('twa.training.max_cards_per_session', 100))) {
            return [
                'card' => null,
                'progress' => $progress,
            ];
        }

        $pick = $this->picker->pickNext((int) $student->id, $lessonId);

        if ($pick === null) {
            return [
                'card' => null,
                'progress' => $progress,
            ];
        }

        $word = $pick['word'];

        return [
            'card' => [
                'word_id' => (int) $word->id,
                'word' => $word->word,
                'translation' => $word->translation,
                'example' => $word->example,
                'transcription' => $word->transcription,
                'card_kind' => $pick['kind'],
            ],
            'progress' => $progress,
        ];
    }

    private function unauthorizedStudent(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'student_not_found', 'message' => 'Unauthorized'],
        ], 401);
    }

    /**
     * @return array{0: Student, 1: TrainingSession}|array{0: null, 1: JsonResponse}
     */
    private function resolve(Request $request, int $sessionId): array
    {
        /** @var Student $student */
        $student = $request->attributes->get('student');

        /** @var TrainingSession|null $session */
        $session = TrainingSession::query()->find($sessionId);

        if ($session === null) {
            return [null, response()->json([
                'error' => ['code' => 'session_not_found', 'message' => 'Training session not found.'],
            ], 404)];
        }

        if (! $this->policy->canAccess($student, $session)) {
            return [null, response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'You cannot access this session.'],
            ], 403)];
        }

        if ($session->status !== 'open') {
            return [null, response()->json([
                'error' => ['code' => 'session_closed', 'message' => 'This training session is closed.'],
            ], 410)];
        }

        return [$student, $session];
    }
}
