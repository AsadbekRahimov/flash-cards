<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Services\LevelReadinessService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\ReadinessResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/twa/courses/{slug}/readiness?source=today|profile|course` (docs/04 A.20): the readiness of the learner to the level
 * of a course, its modules and the quiz of the week. 404 `readiness_disabled` while `READINESS_ENABLED` is off.
 */
final class ReadinessController
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly LevelReadinessService $readiness,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        if (! $this->readiness->enabled()) {
            return ApiErrorResource::respond(404, 'readiness_disabled', 'Readiness is switched off.');
        }

        $course = $this->catalog->findAvailable($slug, $learner);
        if ($course === null) {
            return ApiErrorResource::respond(404, 'course_not_found', 'Course not found.');
        }

        $screen = $this->readiness->screen($course, $learner, $request->query('source') === null ? '' : (string) $request->query('source'));

        return (new ReadinessResource($screen))->response();
    }
}
