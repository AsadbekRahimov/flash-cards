<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseDetail;
use Illuminate\Http\Request;

/**
 * GET /api/twa/courses/{slug}: the course with its stages ("modules") and lessons in study
 * order, each with the learner's progress over active words.
 *
 * @property CourseDetail $resource
 */
final class CourseDetailResource extends TwaResource
{
    /**
     * @return array{
     *   slug: string,
     *   title: string,
     *   description: string|null,
     *   level: string|null,
     *   exam_tag: string|null,
     *   words_count: int,
     *   is_active: bool,
     *   progress: array{done: int, total: int},
     *   stages: list<array{
     *     number: int,
     *     title: string,
     *     progress: array{done: int, total: int},
     *     lessons: list<array{id: int, number: int, title: string|null, progress: array{done: int, total: int}}>
     *   }>
     * }
     */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;
        $course = $detail->course;

        /** @var array<int, array{number: int, title: string, done: int, total: int, lessons: list<array{id: int, number: int, title: string|null, progress: array{done: int, total: int}}>}> $stages */
        $stages = [];
        foreach ($detail->lessons as $lesson) {
            $stages[$lesson->stageNumber] ??= [
                'number' => $lesson->stageNumber,
                'title' => $lesson->stageTitle,
                'done' => 0,
                'total' => 0,
                'lessons' => [],
            ];
            $stages[$lesson->stageNumber]['done'] += $lesson->progress->done;
            $stages[$lesson->stageNumber]['total'] += $lesson->progress->total;
            $stages[$lesson->stageNumber]['lessons'][] = [
                'id' => $lesson->lessonId,
                'number' => $lesson->lessonNumber,
                'title' => $lesson->lessonTitle,
                'progress' => ['done' => $lesson->progress->done, 'total' => $lesson->progress->total],
            ];
        }

        return [
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'level' => $course->level,
            'exam_tag' => $course->examTag,
            'words_count' => $course->wordsCount,
            'is_active' => $detail->isActive,
            'progress' => ['done' => $detail->progress->done, 'total' => $detail->progress->total],
            'stages' => array_values(array_map(
                static fn (array $stage): array => [
                    'number' => $stage['number'],
                    'title' => $stage['title'],
                    'progress' => ['done' => $stage['done'], 'total' => $stage['total']],
                    'lessons' => $stage['lessons'],
                ],
                $stages,
            )),
        ];
    }
}
