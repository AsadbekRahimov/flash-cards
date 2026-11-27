<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\TwaOnboardingCompletion;
use Illuminate\Http\Request;

/**
 * POST /api/twa/onboarding/complete (docs/40 §2.1, docs/04 A.24): the onboarding is done; the learner's active course and the next
 * lesson in it ("Start the first lesson" opens "Today", which offers it as step 1).
 *
 * @property TwaOnboardingCompletion $resource
 */
final class OnboardingCompletedResource extends TwaResource
{
    /** @return array{completed: true, course: array{slug: string, title: string}|null, next_lesson_id: int|null} */
    public function toArray(Request $request): array
    {
        return [
            'completed' => true,
            'course' => $this->resource->course,
            'next_lesson_id' => $this->resource->nextLessonId,
        ];
    }
}
