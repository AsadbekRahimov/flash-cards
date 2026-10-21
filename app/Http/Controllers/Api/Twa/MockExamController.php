<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\MockExamException;
use App\Domain\Learning\Services\MockExamService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\MockExamSessionResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/twa/courses/{slug}/mock-exam` (docs/04 A.4a): opens the learner's
 * IELTS / SAT mock of the course (201) or returns the one already open (200).
 * The mock is then played on the ordinary `/exam/sessions/{id}/*` routes.
 */
final class MockExamController
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly MockExamService $mocks,
    ) {}

    public function open(Request $request, string $slug): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $course = $this->catalog->findAvailable($slug, $learner);
        if ($course === null) {
            return ApiErrorResource::respond(404, 'course_not_found', 'Course not found.');
        }

        try {
            $session = $this->mocks->open($learner, $course);
        } catch (MockExamException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new MockExamSessionResource($session))
            ->response()
            ->setStatusCode($session->wasRecentlyCreated ? 201 : 200);
    }
}
