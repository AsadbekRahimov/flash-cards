<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Twa\Services\MePayloadBuilder;
use App\Http\Requests\Twa\UpdateSettingsRequest;
use App\Http\Resources\Twa\MeResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

final class SettingsController
{
    public function __construct(
        private readonly LearnerSettingsService $settings,
        private readonly MePayloadBuilder $payload,
    ) {}

    /** PATCH /api/twa/me/settings — answers with the refreshed profile, like `GET /me`. */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $this->settings->update($learner, $request->validated());

        return (new MeResource($this->payload->build($learner)))->response();
    }
}
