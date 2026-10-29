<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Http\Requests\Twa\OpenPracticeSessionRequest;
use App\Http\Requests\Twa\PracticeAnswerRequest;
use App\Http\Requests\Twa\PracticeReplaceRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\PracticeAnswerResource;
use App\Http\Resources\Twa\PracticeSessionResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The solo practice (docs/16 §7.2): open, read, answer, swap. Thin: the request is validated by a FormRequest, the work is
 * PracticeSessionService's, the body is a Resource. Somebody else's session is "not found".
 */
final class PracticeSessionController
{
    public function __construct(private readonly PracticeSessionService $sessions) {}

    /** POST /api/twa/practice/sessions — 201 with the new session (or the one a parallel tab has just opened). */
    public function store(OpenPracticeSessionRequest $request): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $session = $this->sessions->open(
                $learner,
                $request->mode(),
                $request->course(),
                $request->lessonId(),
                $request->source(),
                $request->replaceActive(),
                $request->retryOf(),
            );

            return (new PracticeSessionResource($this->sessions->view($session, $learner)))->response()->setStatusCode(201);
        } catch (PracticeException $e) {
            return $this->error($e);
        }
    }

    /** GET /api/twa/practice/sessions/{session} — an ended session answers 200 with its summary. */
    public function show(Request $request, int $session): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $found = $this->sessions->find($learner, $session);

            return (new PracticeSessionResource($this->sessions->view($found, $learner)))->response();
        } catch (PracticeException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/practice/sessions/{session}/answer */
    public function answer(PracticeAnswerRequest $request, int $session): JsonResponse
    {
        try {
            $outcome = $this->sessions->answer(
                $session,
                $this->learner($request),
                $request->requestId(),
                $request->elementId(),
                $request->answer(),
                $request->timeSpentMs(),
            );

            return (new PracticeAnswerResource($outcome))->response();
        } catch (PracticeException $e) {
            return $this->error($e);
        }
    }

    /** POST /api/twa/practice/sessions/{session}/replace */
    public function replace(PracticeReplaceRequest $request, int $session): JsonResponse
    {
        $learner = $this->learner($request);

        try {
            $view = $this->sessions->replace($session, $learner, $request->requestId(), $request->elementId(), $request->reason());

            return (new PracticeSessionResource($view))->response();
        } catch (PracticeException $e) {
            return $this->error($e);
        }
    }

    private function error(PracticeException $e): JsonResponse
    {
        return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
    }

    private function learner(Request $request): Learner
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return $learner;
    }
}
