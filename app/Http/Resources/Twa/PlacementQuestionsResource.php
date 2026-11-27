<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\PlacementQuestions;
use Illuminate\Http\Request;

/**
 * POST /api/twa/onboarding/placement → `{set_id, questions: [{id, word, options}], expires_at}` (docs/40 §2.1a, docs/04 A.27). No right
 * answer leaves the server.
 *
 * @property PlacementQuestions $resource
 */
final class PlacementQuestionsResource extends TwaResource
{
    /** @return array{set_id: string, questions: list<array{id: int, word: string, options: list<string>}>, expires_at: string} */
    public function toArray(Request $request): array
    {
        return [
            'set_id' => $this->resource->setId,
            'questions' => $this->resource->questions,
            'expires_at' => $this->resource->expiresAt->utc()->toIso8601String(),
        ];
    }
}
