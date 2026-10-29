<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeHubService;
use App\Http\Requests\Twa\PracticeHubRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\PracticeHubResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;

/** GET /api/twa/practice/hub (docs/16 §7.1): the four modes of a course / lesson, the unfinished session, the battle summary. */
final class PracticeHubController
{
    public function __construct(private readonly PracticeHubService $hubs) {}

    public function show(PracticeHubRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        try {
            $hub = $this->hubs->hub($learner, $request->course(), $request->lessonId());
        } catch (PracticeException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details);
        }

        return (new PracticeHubResource($hub))->response();
    }
}
