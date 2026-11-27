<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\TwaOnboardingState;
use Illuminate\Http\Request;

/**
 * GET /api/twa/onboarding and the answer of PUT /api/twa/onboarding/steps/{step} (docs/40 §2.1, docs/04 A.24): the state of the
 * onboarding of the Mini App. The age range and the goals are the learner's own and are shown nowhere else (docs/40 §3, В5).
 *
 * @phpstan-import-type StateValues from TwaOnboardingState
 * @phpstan-import-type StateOptions from TwaOnboardingState
 *
 * @property TwaOnboardingState $resource
 */
final class OnboardingStateResource extends TwaResource
{
    /** @return array{completed: bool, step: string|null, values: StateValues, options: StateOptions} */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
