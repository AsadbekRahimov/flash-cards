<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupAnswerOutcome;
use App\Http\Resources\Twa\TwaResource;
use Illuminate\Http\Request;

/**
 * `POST /cup-rounds/{attempt}/answer` (docs/04 A.23): the stored body of the answer - `{verdict, points, reveal, finished,
 * total_points}`, the same for a repeated `request_id` - picked key by key, and the attempt after it (`round`: the next task or the
 * review). `reveal` is the right answer of the task just answered, by right; the next task has none.
 *
 * @property CupAnswerOutcome $resource
 */
final class CupRoundAnswerResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $outcome = $this->resource;
        $stored = $outcome->body;

        return [
            'verdict' => $stored['verdict'] ?? null,
            'points' => (int) ($stored['points'] ?? 0),
            'reveal' => $stored['reveal'] ?? null,
            'finished' => (bool) ($stored['finished'] ?? false),
            'total_points' => (int) ($stored['total_points'] ?? 0),
            'replayed' => $outcome->replayed,
            'round' => CupRoundResource::shape($outcome->view),
        ];
    }
}
