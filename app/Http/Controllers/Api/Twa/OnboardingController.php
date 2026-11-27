<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Domain\Learners\Exceptions\InvalidSettingException;
use App\Domain\Learners\Exceptions\TwaOnboardingException;
use App\Domain\Learners\Services\TwaOnboardingService;
use App\Http\Requests\Twa\OnboardingStepRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\OnboardingCompletedResource;
use App\Http\Resources\Twa\OnboardingStateResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The onboarding of the Mini App, O1-O8 (docs/40 §2.1, docs/04 A.24). Errors: 409 `onboarding_completed`, 422 `display_name_invalid
 * {reason}` / `onboarding_incomplete {missing}` / validation, 403 `daily_goal_pro_required {max}`.
 */
final class OnboardingController
{
    public function __construct(private readonly TwaOnboardingService $onboarding) {}

    /** GET /api/twa/onboarding */
    public function show(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new OnboardingStateResource($this->onboarding->state($learner)))->response();
    }

    /** PUT /api/twa/onboarding/steps/{step} */
    public function update(OnboardingStepRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $state = $this->onboarding->saveStep($learner, $request->step(), $request->body());
        } catch (TwaOnboardingException|DailyGoalLimitException $e) {
            return $this->error($e);
        } catch (InvalidSettingException $e) {
            // The service's own line of defence (the course of another level, a value the request let through): a validation error.
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return (new OnboardingStateResource($state))->response();
    }

    /** POST /api/twa/onboarding/complete */
    public function complete(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $completion = $this->onboarding->complete($learner);
        } catch (TwaOnboardingException $e) {
            return $this->error($e);
        }

        return (new OnboardingCompletedResource($completion))->response();
    }

    private function error(TwaOnboardingException|DailyGoalLimitException $e): JsonResponse
    {
        return $e instanceof DailyGoalLimitException
            ? ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details)
            : ApiErrorResource::respond($e->httpStatus, $e->errorCode, $e->getMessage(), $e->details);
    }
}
