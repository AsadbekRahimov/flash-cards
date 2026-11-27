<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learners\Services\PlacementTestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/onboarding/placement/answers (docs/40 §2.1a, docs/04 A.27): `{answers: (int|null)[]}` - one entry per question in the
 * order of the questions, the index of the option chosen (`0 … 3`) or `null` for a skipped question. Strict types; the count and the
 * range per question are the service's (it knows the draft).
 */
final class PlacementAnswersRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'list', 'min:1', 'max:'.(PlacementTestService::WORDS_PER_LEVEL * count(PlacementTestService::LEVELS))],
            'answers.*' => ['present', 'nullable', 'integer:strict', 'min:0', 'max:3'],
        ];
    }

    /** @return list<int|null> */
    public function answers(): array
    {
        /** @var list<int|null> */
        return array_values((array) $this->validated('answers', []));
    }
}
