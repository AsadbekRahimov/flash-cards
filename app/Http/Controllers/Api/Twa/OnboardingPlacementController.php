<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\TwaOnboardingException;
use App\Domain\Learners\Services\PlacementTestService;
use App\Http\Requests\Twa\PlacementAnswersRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\PlacementQuestionsResource;
use App\Http\Resources\Twa\PlacementResultResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The placement test of the onboarding of the Mini App (docs/40 §2.1a, docs/04 A.27). Errors: 409 `onboarding_completed`, 410
 * `placement_expired`, 422 `placement_unavailable` / validation.
 */
final class OnboardingPlacementController
{
    public function __construct(private readonly PlacementTestService $placement) {}

    /** POST /api/twa/onboarding/placement - 201 `{questions, expires_at}`. */
    public function start(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            return (new PlacementQuestionsResource($this->placement->start($learner)))->response()->setStatusCode(201);
        } catch (TwaOnboardingException $e) {
            return ApiErrorResource::respond($e->httpStatus, $e->errorCode, $e->getMessage(), $e->details);
        }
    }

    /** POST /api/twa/onboarding/placement/answers - `{level, course_slug, correct_by_level}`. */
    public function answer(PlacementAnswersRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $result = $this->placement->answer($learner, $request->answers());
        } catch (TwaOnboardingException $e) {
            return ApiErrorResource::respond($e->httpStatus, $e->errorCode, $e->getMessage(), $e->details);
        } catch (InvalidSettingException $e) {
            // The answers do not match the questions of the draft (count, range): a validation error.
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return (new PlacementResultResource($result))->response();
    }
}
