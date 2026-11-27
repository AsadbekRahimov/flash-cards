<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\PlacementResult;
use Illuminate\Http\Request;

/**
 * POST /api/twa/onboarding/placement/answers → `{level, course_slug, correct_by_level}` (docs/40 §2.1a, docs/04 A.27).
 *
 * @property PlacementResult $resource
 */
final class PlacementResultResource extends TwaResource
{
    /** @return array{level: string, course_slug: string, correct_by_level: array<string, int>} */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
