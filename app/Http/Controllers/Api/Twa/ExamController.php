<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\ExamParticipationException;
use App\Domain\Learning\Services\ExamFlowService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ExamAnswerResource;
use App\Http\Resources\Twa\ExamJoinResource;
use App\Http\Resources\Twa\ExamQuestionResource;
use App\Http\Resources\Twa\ExamResultResource;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Policies\ExamSessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExamController
{
    public function __construct(
        private readonly ExamSessionPolicy $policy,
        private readonly ExamFlowService $flow,
    ) {}

    public function join(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        return (new ExamJoinResource($this->flow->join($session, $learner)))->response();
    }

    public function question(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        $served = $this->flow->serveQuestion($session, $learner, (int) $request->query('index', 0));

        if ($served === null) {
            return ApiErrorResource::respond(404, 'question_not_found', 'Question index is out of range.');
        }

        return (new ExamQuestionResource($served))->response();
    }

    public function answer(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId, requireOpen: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        $validated = $request->validate([
            'question_index' => ['required', 'integer', 'min:0'],
            'word_id' => ['required', 'integer'],
            'selected_option_index' => ['nullable', 'integer', 'min:0'],
            // Retained for backwards-compatible client telemetry. Scoring uses server time only.
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
        ]);

        $selectedIndex = $validated['selected_option_index'] ?? null;

        try {
            $feedback = $this->flow->answer(
                $session,
                $learner,
                (int) $validated['question_index'],
                (int) $validated['word_id'],
                $selectedIndex !== null ? (int) $selectedIndex : null,
            );
        } catch (ExamParticipationException $e) {
            return ApiErrorResource::respond($e->status, $e->errorCode, $e->getMessage());
        }

        return (new ExamAnswerResource($feedback))->response();
    }

    public function result(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId, requireOpen: false);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        if (! $this->policy->canViewResult($learner, $session)) {
            return ApiErrorResource::respond(
                409,
                'exam_still_open',
                'Results are available only after the exam is closed.',
            );
        }

        return (new ExamResultResource($this->flow->result($session, $learner)))->response();
    }

    /**
     * @return array{0: Learner, 1: ExamSession}|array{0: null, 1: JsonResponse}
     */
    private function resolve(Request $request, int $sessionId, bool $requireOpen): array
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        /** @var ExamSession|null $session */
        $session = ExamSession::query()->find($sessionId);

        if ($session === null) {
            return [null, ApiErrorResource::respond(404, 'session_not_found', 'Exam session not found.')];
        }

        $denial = $this->policy->denial($learner, $session);
        if ($denial !== null) {
            return [null, ApiErrorResource::respond(403, $denial, 'You cannot access this exam session.')];
        }

        if ($requireOpen && (
            $session->status !== 'open'
            || ($session->ends_at !== null && ! $session->ends_at->isFuture())
        )) {
            return [null, ApiErrorResource::respond(410, 'session_closed', 'This exam session is closed.')];
        }

        return [$learner, $session];
    }

    private function unauthorizedLearner(): JsonResponse
    {
        return ApiErrorResource::respond(401, 'learner_not_found', 'Unauthorized');
    }
}
