<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Exceptions\TimezoneChangeTooSoonException;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Requests\Twa\UpdateSettingsRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

final class SettingsController
{
    public function __construct(
        private readonly LearnerSettingsService $settings,
        private readonly MePayloadBuilder $payload,
    ) {}

    /**
     * PATCH /api/twa/me/settings — answers with the refreshed profile, like `GET /me`;
     * 422 `timezone_change_too_soon` when the zone was changed less than 24 h ago (nothing is applied).
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $this->settings->update($learner, $request->validated());
        } catch (TimezoneChangeTooSoonException $e) {
            return ApiErrorResource::respond(422, $e->errorCode, $e->getMessage());
        }

        return (new MeResource($this->payload->build($learner)))->response();
    }
}
