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
     *   section: string,
     *   words_count: int,
     *   stages_count: int|null,
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
            // The section of the catalog (docs/35 §3.3 a): `phrases` - the course of phrases, `courses` - every other.
            'section' => $course->section(),
            'words_count' => $course->wordsCount,
            // The modules of the course (docs/40 §2.1): from the cached catalog; null for a course outside it (a deck, an unlisted one).
            'stages_count' => $course->stagesCount,
            'is_featured' => $course->isFeatured,
            'is_active' => $listing->isActive,
            'progress' => [
                'done' => $listing->progress->done,
                'total' => $listing->progress->total,
            ],
        ];
    }
}
