<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Lesson;
use App\Models\Stage;
use Illuminate\Support\Facades\DB;

/**
 * The cached tree of a course (modules, lessons, their word totals; docs/19 K3) is made of `stages` and `lessons`: whatever
 * path creates, renames, moves or deletes one of them (the admin panel's forms, an import, a console command) drops the tree
 * of the course after the commit. The words of a course are the other half, and every path that changes them ends in
 * `CoursePublisher::recount()`, which drops it as well. Registered in AppServiceProvider for {@see Stage} and {@see Lesson};
 * query-builder updates bypass model events and must go through Eloquent (or call `forgetCourseTree()` themselves).
 */
final class CourseTreeObserver
{
    public function __construct(private readonly CourseCatalogService $catalog) {}

    public function saved(Stage|Lesson $model): void
    {
        $courseIds = [$this->courseOf($model, original: false)];

        // Moved to another module / course: the tree it left changes too.
        if ($model->wasChanged($model instanceof Stage ? 'course_id' : 'stage_id')) {
            $courseIds[] = $this->courseOf($model, original: true);
        }

        $this->forgetAfterCommit($courseIds);
    }

    public function deleted(Stage|Lesson $model): void
    {
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
        $courseId = is_numeric($stageId) ? Stage::query()->withoutGlobalScopes()->whereKey((int) $stageId)->value('course_id') : null;

        return is_numeric($courseId) ? (int) $courseId : null;
    }
}
