<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Content\DTO\CourseListing;
use Illuminate\Http\Request;

/**
 * GET /api/twa/courses: `{"courses": [CourseResource, ...]}` in catalog order.
 *
 * @property list<CourseListing> $resource
 */
final class CourseListResource extends TwaResource
{
    /**
     * @return array{courses: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        return [
            'courses' => array_map(
                fn (CourseListing $listing): array => (new CourseResource($listing))->toArray($request),
                $this->resource,
            ),
        ];
    }
}
