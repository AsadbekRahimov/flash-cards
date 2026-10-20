<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\Services\TodayService;
use App\Domain\Learning\Exceptions\ReviewSubmissionException;
use App\Domain\Learning\Exceptions\TrainingSessionException;
use App\Domain\Learning\Services\TrainingFlowService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Http\Requests\Twa\OpenTrainingSessionRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ReviewResultResource;
use App\Http\Resources\Twa\SessionSummaryResource;
use App\Http\Resources\Twa\TrainingNextResource;
use App\Http\Resources\Twa\TrainingStartResource;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TrainingSession;
use App\Policies\TrainingSessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrainingController
{
    public function __construct(
        private readonly TrainingFlowService $flow,
        private readonly TrainingSessionService $sessions,
        private readonly TrainingSessionPolicy $policy,
        private readonly ProductEventRecorder $events,
        private readonly TodayService $today,
    ) {}

    /**
     * POST /api/twa/training/sessions — opens (or returns the already open) personal session
     * and answers like `/start`, so the client needs one request to show the first card.
     */
    public function open(OpenTrainingSessionRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $lesson = null;
        if ($request->lessonId() !== null) {
            $lesson = Lesson::query()->find($request->lessonId());
            if ($lesson === null) {
                return $this->lessonNotFound();
            }
        }

        $mode = $request->string('mode')->toString();
        $source = $request->source();

        try {
            $session = $this->sessions->openPersonal($learner, $mode, $lesson, $source);
        } catch (TrainingSessionException $e) {
            return match ($e->reason) {
                TrainingSessionException::REASON_LESSON_NOT_AVAILABLE => $this->lessonNotFound(),
                TrainingSessionException::REASON_INVALID_MODE,
                TrainingSessionException::REASON_LESSON_MISMATCH => ApiErrorResource::respond(422, $e->reason, $e->getMessage()),
                default => throw $e,
            };
        }

        $this->events->record(
            ProductEventName::TrainingSessionOpened,
            [
                'training_session_id' => $session->id,
                'mode' => $mode,
                'source' => $source,
                'reused' => ! $session->wasRecentlyCreated,
            ],
            learner: $learner,
        );

        $start = $this->flow->start($session, $learner);
        if ($start === null) {
            return $this->lessonNotFound();
        }

        return (new TrainingStartResource($start))->response();
    }

    /**
     * POST /api/twa/training/sessions/{id}/finish — idempotent: a repeat answers 200 with the
     * same numbers. Unlike the other session routes a closed session is not 410 here.
     */
    public function finish(Request $request, int $sessionId): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        /** @var TrainingSession|null $session */
        $session = TrainingSession::query()->find($sessionId);
        if ($session === null) {
            return ApiErrorResource::respond(404, 'session_not_found', 'Training session not found.');
        }

        $denial = $this->policy->denial($learner, $session);
        if ($denial !== null) {
            return ApiErrorResource::respond(403, $denial, 'You cannot access this session.');
        }

        // Group sessions are closed by the teacher / scheduler, never by a learner.
        if (! $this->policy->canFinish($learner, $session)) {
            return ApiErrorResource::respond(403, 'forbidden', 'You cannot finish this session.');
        }

        try {
            $summary = $this->sessions->finish($session);
        } catch (TrainingSessionException $e) {
            return ApiErrorResource::respond(403, $e->reason, $e->getMessage());
        }

        if ($summary->wasOpen) {
            $this->events->record(
                ProductEventName::TrainingSessionFinished,
                [
                    'training_session_id' => $session->id,
                    'mode' => $session->mode,
                    'reviewed' => $summary->reviewed,
                    'correct' => $summary->correct,
                    'new_words' => $summary->newWords,
                ],
                learner: $learner,
            );
        }

        return (new SessionSummaryResource(['summary' => $summary, 'progress' => $this->today->progress($learner)]))->response();
    }

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
            return $this->lessonNotFound();
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

    private function lessonNotFound(): JsonResponse
    {
        return ApiErrorResource::respond(404, 'lesson_not_found', 'Training lesson not found.');
    }

    private function lessonNotAvailable(): JsonResponse
    {
        return ApiErrorResource::respond(404, 'lesson_not_available', 'This lesson is no longer available.');
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

        if (! $session->isLiveAt(CarbonImmutable::now())) {
            return [null, ApiErrorResource::respond(410, 'session_closed', 'This training session is closed.')];
        }

        // A center's private lesson stops opening once the assignment or the membership is gone.
        if (! $this->sessions->ensureStillAvailable($session, $learner)) {
            return [null, $this->lessonNotAvailable()];
        }

        return [$learner, $session];
    }
}
