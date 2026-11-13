<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use Illuminate\Support\Facades\DB;

/**
 * The cached tree of a course (modules, lessons, their word totals; docs/19 K3) is made of `stages` and `lessons`: whatever
 * path creates, renames, moves or deletes one of them (the admin panel's forms, an import, a console command) drops the tree
 * of the course after the commit. The words of a course are the other half, and every path that changes them ends in
 * `CoursePublisher::recount()`, which drops it as well. Attached with `#[ObservedBy]` to {@see Stage}, {@see Lesson} and
 * {@see Course}; query-builder updates bypass model events and must go through Eloquent (or call `forgetCourseTree()` themselves).
 *
 * The data version the tree is keyed with ({@see CourseCatalogService::courseTreeVersionKey()}) also keys the new-word frontier
 * of every learner of the course (`App\Domain\Learning\Services\NewWordFrontier`), which depends on the course being AVAILABLE
 * to them as well: a saved {@see Course} whose `status`, `visibility` or owner changed moves the version on too (the status
 * change of `CoursePublisher::transition()` does it itself; twice is harmless). A course that is only renamed does not.
 *
 * The course of a lesson is its module's: read from the module when the lesson carries it (the relation is loaded, it is the module
 * asked about, and it has its `course_id` - a module selected without that column does not count), and with one primary-key lookup
 * when it does not.
 */
final class CourseTreeObserver
{
    /** The columns of `courses` the availability rule reads (`Course::scopeAvailableTo()`, `CourseCatalogService::isAvailable()`). */
    private const AVAILABILITY_COLUMNS = ['status', 'visibility', 'owner_type', 'owner_id'];

    public function __construct(private readonly CourseCatalogService $catalog) {}

    public function saved(Stage|Lesson|Course $model): void
    {
        if ($model instanceof Course) {
            if ($model->wasChanged(self::AVAILABILITY_COLUMNS)) {
                $this->forgetAfterCommit([(int) $model->getKey()]);
            }

            return;
        }

        $courseIds = [$this->courseOf($model, original: false)];

        // Moved to another module / course: the tree it left changes too.
        if ($model->wasChanged($model instanceof Stage ? 'course_id' : 'stage_id')) {
            $courseIds[] = $this->courseOf($model, original: true);
        }

        $this->forgetAfterCommit($courseIds);
    }

    public function deleted(Stage|Lesson|Course $model): void
    {
        // A deleted course has no readers left; its entries expire by their TTL.
        if ($model instanceof Course) {
            return;
        }

        $this->forgetAfterCommit([$this->courseOf($model, original: false)]);
    }

    /** @param  list<int|null>  $courseIds */
    private function forgetAfterCommit(array $courseIds): void
    {
        $ids = array_values(array_unique(array_filter($courseIds)));

        DB::afterCommit(function () use ($ids): void {
            foreach ($ids as $courseId) {
                $this->catalog->forgetCourseTree($courseId);
            }
        });
    }

    private function courseOf(Stage|Lesson $model, bool $original): ?int
    {
        if ($model instanceof Stage) {
            $courseId = $original ? $model->getOriginal('course_id') : $model->course_id;

            return is_numeric($courseId) ? (int) $courseId : null;
        }

        $stageId = $original ? $model->getOriginal('stage_id') : $model->stage_id;

        if (! is_numeric($stageId)) {
            return null;
        }

        // The module in hand is the module asked about only if their ids agree (a lesson moved to another module still carries the old one)
        // and it was read WITH its course: a list that selects a few columns of the module (`lesson.stage:id,number`) leaves `course_id`
        // out, and "no value" there is not "no course" - it would silently leave the tree of the course in the cache.
        $loaded = $model->relationLoaded('stage') ? $model->getRelation('stage') : null;
        $courseId = $loaded instanceof Stage && (int) $loaded->getKey() === (int) $stageId && array_key_exists('course_id', $loaded->getAttributes())
            ? $loaded->course_id
            : Stage::query()->withoutGlobalScopes()->whereKey((int) $stageId)->value('course_id');

        return is_numeric($courseId) ? (int) $courseId : null;
    }
}
