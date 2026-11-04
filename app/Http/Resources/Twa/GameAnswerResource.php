<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\HintOutcome;
use App\Domain\Game\DTO\RunAnswerOutcome;
use Illuminate\Http\Request;

/**
 * `POST /game/runs/{id}/answer` and `POST /game/runs/{id}/hint` (docs/18 §12.8). The answer: the stored body of the attempt (the same
 * bytes for a repeated `request_id`), picked key by key - `pair` / `round_done` only for a round of pairs - and the run of now. The
 * hint: `{hint, coins: {balance}}` and the run of now.
 *
 * @property RunAnswerOutcome|HintOutcome $resource
 */
final class GameAnswerResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $outcome = $this->resource;
        $stored = $outcome->body;

        if ($outcome instanceof HintOutcome) {
            $body = ['hint' => $stored['hint'] ?? null, 'coins' => ['balance' => (int) ($stored['coins']['balance'] ?? 0)]];
        } else {
            $body = [
                'verdict' => $stored['verdict'] ?? null,
                'points' => $stored['points'] ?? 0,
                'reveal' => $stored['reveal'] ?? null,
                'flask_lost' => $stored['flask_lost'] ?? false,
                'combo' => $stored['combo'] ?? 0,
                'event_applied' => $stored['event_applied'] ?? null,
            ];
            foreach (['pair', 'round_done'] as $key) {
                if (array_key_exists($key, $stored)) {
                    $body[$key] = $stored[$key];
                }
            }
        }
        $body['run'] = (new GameRunResource($outcome->run))->toArray($request);

        return $body;
    }
}
