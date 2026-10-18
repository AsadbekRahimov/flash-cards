<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Single place that knows which course content belongs to (docs/12 §1.7, §3.2):
 * the platform `default` course, the course of a group, and stage / lesson
 * lookup INSIDE a course — stage numbers are unique per course, not globally.
 *
 * Catalog listings for learners (published / forLearner / progress) arrive
 * with task 1.2b.
 */
final class CourseCatalogService
{
    /**
     * The platform course that receives demo content and imports without an
     * explicit course, and serves groups whose `course_id` is NULL. Created
     * on first use (race-safe).
     */
    public function defaultCourse(): Course
    {
        return Course::query()->createOrFirst(
            ['slug' => (string) config('content.default_course_slug')],
            [
                'owner_type' => 'platform',
                'title' => ['ru' => 'Базовый курс', 'uz' => 'Asosiy kurs'],
                'visibility' => 'public',
                'status' => 'published',
                'published_at' => now(),
            ],
        );
    }

    public function findBySlug(string $slug): ?Course
    {
        return Course::query()->where('slug', $slug)->first();
    }

    /** The course teacher commands of $group (`/start_training`, `/start_exam`) work in. */
    public function courseForGroup(TelegramGroup $group): Course
    {
        if ($group->course_id !== null) {
            $course = $group->course;
            if ($course !== null) {
                return $course;
            }
        }

        return $this->defaultCourse();
    }

    public function findStage(Course $course, int $stageNumber): ?Stage
    {
        return Stage::query()
            ->where('course_id', $course->id)
            ->where('number', $stageNumber)
            ->first();
    }

    public function findLesson(Stage $stage, int $lessonNumber): ?Lesson
    {
        return Lesson::query()
            ->where('stage_id', $stage->id)
            ->where('number', $lessonNumber)
            ->first();
    }

    /**
     * The first lesson of the course (in study order) that still has an active word the
     * learner has never been served — where `daily_review` takes its new words from
     * (docs/12 §7.3). Null when the learner has seen the whole course.
     */
    public function nextLessonWithNewWords(Course $course, Learner $learner): ?Lesson
    {
        return Lesson::query()
            ->select('lessons.*')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->whereExists(fn (QueryBuilder $q): QueryBuilder => $q
                ->from('words')
                ->whereColumn('words.lesson_id', 'lessons.id')
                ->where('words.is_active', true)
                ->whereNotExists(fn (QueryBuilder $seen): QueryBuilder => $seen
                    ->from('word_repetitions')
                    ->whereColumn('word_repetitions.word_id', 'words.id')
                    ->where('word_repetitions.learner_id', $learner->id)))
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->with('stage')
            ->first();
    }

    /**
     * Lessons of the course in study order `(stage.number, lesson.number)`,
     * with their `stage` loaded.
     *
     * @return Collection<int, Lesson>
     */
    public function lessons(Course $course, ?int $limit = null): Collection
    {
        $query = Lesson::query()
            ->select('lessons.*')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->with('stage');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
