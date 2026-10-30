<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleAnswerOutcome;
use App\Domain\Practice\Support\IsoTime;
use Illuminate\Http\Request;

/**
 * POST /api/twa/practice/battles/{id}/answer (docs/16 §7.4): the verdict and the points of THIS player, the server time the answer
 * was taken, whether it resolved the round (the opponent had answered already), and the poll of the match as it is now. No review:
 * the right answer and the opponent's come with the resolved round (`GET …/round`). A repeated `request_id` answers the same body.
 *
 * @property BattleAnswerOutcome $resource
 */
final class BattleAnswerResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $outcome = $this->resource;

        return [
            'verdict' => $outcome->verdict->value,
            'points' => $outcome->points,
            'accepted_at' => IsoTime::format($outcome->acceptedAt),
            'round_resolved' => $outcome->roundResolved,
            'poll' => (new BattlePollResource($outcome->poll))->toArray($request),
        ];
    }
}
