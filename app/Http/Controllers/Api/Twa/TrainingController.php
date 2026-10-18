<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ReviewResultResource;
use App\Http\Resources\Twa\TrainingNextResource;
use App\Http\Resources\Twa\TrainingStartResource;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Policies\TrainingSessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrainingController
{
    public function __construct(
        private readonly TrainingFlowService $flow,
        private readonly TrainingSessionPolicy $policy,
    ) {}

    public function start(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        $start = $this->flow->start($session, $learner);
        if ($start === null) {
            return ApiErrorResource::respond(404, 'lesson_not_found', 'Training lesson not found.');
        }

        return (new TrainingStartResource($start))->response();
    }

    public function next(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        return (new TrainingNextResource($this->flow->nextStep($session, $learner)))->response();
    }

    public function review(Request $request, int $sessionId): JsonResponse
    {
        [$learner, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $learner instanceof Learner) {
            return $this->unauthorizedLearner();
        }

        $validated = $request->validate([
            'word_id' => ['required', 'integer'],
            'quality' => ['required', 'integer', 'min:0', 'max:5'],
            'time_spent_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'serve_token' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $outcome = $this->flow->review(
                $session,
                $learner,
                (int) $validated['word_id'],
                (int) $validated['quality'],
                (int) $validated['time_spent_ms'],
                isset($validated['serve_token']) ? (string) $validated['serve_token'] : null,
            );
        } catch (ReviewSubmissionException $e) {
            return ApiErrorResource::respond($e->status, $e->errorCode, $e->getMessage());
        }

        return (new ReviewResultResource($outcome))->response();
    }

    private function unauthorizedLearner(): JsonResponse
    {
        return ApiErrorResource::respond(401, 'learner_not_found', 'Unauthorized');
    }

    /**
     * @return array{0: Learner, 1: TrainingSession}|array{0: null, 1: JsonResponse}
     */
    private function resolve(Request $request, int $sessionId): array
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        /** @var TrainingSession|null $session */
        $session = TrainingSession::query()->find($sessionId);

        if ($session === null) {
            return [null, ApiErrorResource::respond(404, 'session_not_found', 'Training session not found.')];
        }

        $denial = $this->policy->denial($learner, $session);
        if ($denial !== null) {
            return [null, ApiErrorResource::respond(403, $denial, 'You cannot access this session.')];
        }

        if ($session->status !== 'open') {
            return [null, ApiErrorResource::respond(410, 'session_closed', 'This training session is closed.')];
        }

        return [$learner, $session];
    }
}
