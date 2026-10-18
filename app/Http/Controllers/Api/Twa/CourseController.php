<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Twa;

use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\Services\LearnerCourseService;
use App\Http\Requests\Twa\SelectCourseRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\Twa\CourseDetailResource;
use App\Http\Resources\Twa\CourseListResource;
use App\Http\Resources\Twa\CourseResource;
use App\Models\Learner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseController
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly LearnerCourseService $learnerCourses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        return (new CourseListResource($this->catalog->forLearner($learner)))->response();
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $course = $this->catalog->findAvailable($slug);
        if ($course === null) {
            return $this->notFound();
        }

        return (new CourseDetailResource($this->catalog->detail($course, $learner)))->response();
    }

    public function select(SelectCourseRequest $request): JsonResponse
    {
        /** @var Learner $learner */
        $learner = $request->attributes->get('learner');

        $course = $this->learnerCourses->select($learner, $request->string('slug')->toString());
        if ($course === null) {
            return $this->notFound();
        }

        return (new CourseResource($this->catalog->listing($course, $learner)))->response();
    }

    private function notFound(): JsonResponse
    {
        return ApiErrorResource::respond(404, 'course_not_found', 'Course not found.');
    }
}
