<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learning\Exceptions\LevelQuizException;
use App\Domain\Learning\Services\LevelQuizService;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\LevelQuizSessionResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/twa/courses/{slug}/level-quiz` (docs/04 A.20): opens the learner's quiz of the week over the course (201) or returns the
 * one already open (200, `resumed`). It is then played on the ordinary `/exam/sessions/{id}/*` routes.
 */
final class LevelQuizController
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly LevelQuizService $quizzes,
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
            $session = $this->quizzes->open($learner, $course);
        } catch (LevelQuizException $e) {
            return ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage());
        }

        return (new LevelQuizSessionResource($session))
            ->response()
            ->setStatusCode($session->wasRecentlyCreated ? 201 : 200);
    }
}
