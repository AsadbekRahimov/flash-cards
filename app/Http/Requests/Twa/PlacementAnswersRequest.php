<?php

declare(strict_types=1);

namespace App\Http\Requests\Twa;

use App\Domain\Learners\Services\PlacementTestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/twa/onboarding/placement/answers (docs/40 §2.1a, docs/04 A.27): `{set_id: string, answers: (int|null)[]}` - the `set_id`
 * the start gave, and one entry per question in the order of the questions, the index of the option chosen (`0 … 3`) or `null` for a
 * skipped question. Strict types; whether the set is the current one, the count and the range per question are the service's (it
 * knows the draft).
 */
final class PlacementAnswersRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'set_id' => ['required', 'string', 'max:64'],
            'answers' => ['required', 'list', 'min:1', 'max:'.(PlacementTestService::WORDS_PER_LEVEL * count(PlacementTestService::LEVELS))],
            'answers.*' => ['present', 'nullable', 'integer:strict', 'min:0', 'max:3'],
        ];
    }

    public function setId(): string
    {
        $setId = $this->validated('set_id');

        return is_string($setId) ? $setId : '';
    }

    /** @return list<int|null> */
    public function answers(): array
    {
        /** @var list<int|null> */
        return array_values((array) $this->validated('answers', []));
    }
}
