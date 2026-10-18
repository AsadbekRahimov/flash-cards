<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ReviewResultResource;
use App\Http\Resources\Twa\TrainingNextResource;
use App\Http\Resources\Twa\TrainingStartResource;
use App\Models\Student;
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
        [$student, $session] = $this->resolve($request, $sessionId);
        if ($session instanceof JsonResponse) {
            return $session;
        }
        if (! $student instanceof Student) {
            return $this->unauthorizedStudent();
        }

        $start = $this->flow->start($session, $student);
        if ($start === null) {
            return ApiErrorResource::respond(404, 'lesson_not_found', 'Training lesson not found.');
        }

        return (new TrainingStartResource($start))->response();
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

        return (new TrainingNextResource($this->flow->nextStep($session, $student)))->response();
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
            $outcome = $this->flow->review(
                $session,
                $student,
                (int) $validated['word_id'],
                (int) $validated['quality'],
                (int) $validated['time_spent_ms'],
            );
        } catch (ReviewSubmissionException $e) {
            return ApiErrorResource::respond($e->status, $e->errorCode, $e->getMessage());
        }

        return (new ReviewResultResource($outcome))->response();
    }

    private function unauthorizedStudent(): JsonResponse
    {
        return ApiErrorResource::respond(401, 'student_not_found', 'Unauthorized');
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
            return [null, ApiErrorResource::respond(404, 'session_not_found', 'Training session not found.')];
        }

        if (! $this->policy->canAccess($student, $session)) {
            return [null, ApiErrorResource::respond(403, 'forbidden', 'You cannot access this session.')];
        }

        if ($session->status !== 'open') {
            return [null, ApiErrorResource::respond(410, 'session_closed', 'This training session is closed.')];
        }

        return [$student, $session];
    }
}
