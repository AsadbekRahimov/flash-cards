<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupDuelView;
use App\Domain\Practice\Support\IsoTime;
use App\Http\Resources\Twa\TwaResource;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * A duel of a cup (docs/04 A.23d, D2): the answer of `POST /cups/{id}/duels`, of `…/accept`, `…/decline`, `…/cancel` and of
 * `GET /cups/{id}/duels/{duel}`, and every row of `GET /cups/{id}/duels`. The players are participants - `participant_id`, the name in the
 * tournament and the emoji of Pro (docs/31 §5а) - never a learner id, a Telegram id or a `request_id`.
 *
 * `role` - who looks: `challenger`, `opponent`, or null (somebody who may accept an open challenge). `battle_id` - the match once the
 * duel is accepted: the screen goes to the existing `/battles/{battle_id}` at once (its room waits 30 s). `points` - once the result is
 * in (`done`), else null. Times are ISO 8601 UTC with milliseconds; `server_now` is the clock of the countdown to `expires_at`.
 *
 * @property CupDuelView $resource
 */
final class CupDuelResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::fields($this->resource);
    }

    /** @return array<string, mixed> */
    public static function fields(CupDuelView $view): array
    {
        $duel = $view->duel;

        return [
            'id' => $duel->id,
            'status' => $duel->status->value,
            'end_reason' => $duel->end_reason?->value,
            'rated_offered' => $duel->rated_offered,
            'rated' => $duel->rated,
            'public' => $duel->is_public,
            'mode' => $duel->mode->value,
            'day' => $duel->day,
            'accepted_day' => $duel->accepted_day,
            'expires_at' => self::iso($duel->expires_at),
            'battle_id' => $duel->battle_id,
            'role' => $view->role(),
            'challenger' => [
                'participant_id' => $duel->challenger_participant_id,
                'display_name' => $view->challengerName,
                'pro_emoji' => $view->challengerEmoji,
            ],
            'opponent' => $duel->opponent_participant_id === null ? null : [
                'participant_id' => $duel->opponent_participant_id,
                'display_name' => (string) $view->opponentName,
                'pro_emoji' => $view->opponentEmoji,
            ],
            'points' => $duel->challenger_points === null ? null : [
                'challenger' => $duel->challenger_points,
                'opponent' => (int) $duel->opponent_points,
            ],
            'winner_participant_id' => $duel->winner_participant_id,
            'server_now' => IsoTime::format($view->now),
        ];
    }

    private static function iso(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : IsoTime::format($time);
    }
}
