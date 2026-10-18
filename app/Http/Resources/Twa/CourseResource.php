<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use Illuminate\Http\Request;

/**
 * One course of GET /api/twa/courses (also the answer of POST /api/twa/me/course).
 * Title and description are already localized for the learner (ru fallback).
 *
 * @property CourseListing $resource
 */
final class CourseResource extends TwaResource
{
    /**
     * @return array{
     *   slug: string,
     *   title: string,
     *   description: string|null,
     *   level: string|null,
     *   exam_tag: string|null,
     *   words_count: int,
     *   is_featured: bool,
     *   is_active: bool,
     *   progress: array{done: int, total: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $listing = $this->resource;
        $course = $listing->course;

        return [
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'level' => $course->level,
            'exam_tag' => $course->examTag,
            'words_count' => $course->wordsCount,
            'is_featured' => $course->isFeatured,
            'is_active' => $listing->isActive,
            'progress' => [
                'done' => $listing->progress->done,
                'total' => $listing->progress->total,
            ],
        ];
    }
}
