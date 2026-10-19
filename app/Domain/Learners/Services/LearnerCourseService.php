<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Learner;

/**
 * The learner's active course (`learners.active_course_id`, docs/12 §1.2): the course new words
 * come from. Only a course that is available in the catalog — published and not private — can be
 * chosen; anything else behaves as if it did not exist.
 */
final class LearnerCourseService
{
    public function __construct(
        private readonly CourseCatalogService $catalog,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @return Course|null the chosen course, or null when no available course has this slug
     */
    public function select(Learner $learner, string $slug): ?Course
    {
        $course = $this->catalog->findAvailable($slug);
        if ($course === null) {
            return null;
        }

        if ($learner->active_course_id !== $course->id) {
            $learner->forceFill(['active_course_id' => $course->id])->save();

            $this->events->record(
                ProductEventName::CourseSelected,
                ['course_id' => $course->id, 'via' => 'twa'],
                learner: $learner,
            );
        }

        return $course;
    }
}
