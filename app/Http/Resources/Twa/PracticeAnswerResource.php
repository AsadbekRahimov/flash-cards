<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\AnswerOutcome;
use Illuminate\Http\Request;

/**
 * POST /api/twa/practice/sessions/{id}/answer (docs/16 §7.2): the stored verdict of the answer and the current session.
 * `round_done` is only there for a matching of pairs. The body is the `result` of the attempt (the same bytes for a repeated
 * request), picked key by key.
 *
 * @property AnswerOutcome $resource
 */
final class PracticeAnswerResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource->result;

        $body = [
            'verdict' => $result['verdict'] ?? null,
            'first_attempt' => $result['first_attempt'] ?? true,
            'reveal' => $result['reveal'] ?? null,
            'pair' => $result['pair'] ?? null,
        ];
        if (array_key_exists('round_done', $result)) {
            $body['round_done'] = (bool) $result['round_done'];
        }
        $body['session'] = (new PracticeSessionResource($this->resource->session))->toArray($request);

        return $body;
    }
}
