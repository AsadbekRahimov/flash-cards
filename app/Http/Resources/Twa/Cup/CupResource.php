<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupBoard;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Practice\Support\IsoTime;
use App\Http\Resources\Twa\TwaResource;
use Illuminate\Http\Request;

/**
 * `GET /cups/{id}` (docs/04 A.23): the cup, its table (place, name in the tournament, emoji of Pro, total, "left the chat") and, for a
 * participant, his day and his days (which counted). `can_manage` is a hint for the link to the settings - every action of an
 * administrator asks Telegram again. `server_now` is the clock of the timer of the screen.
 *
 * @property CupBoard $resource
 */
final class CupResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $board = $this->resource;
        $cup = $board->context->cup;
        $mine = $board->context->participant;

        return [
            'cup' => [...CupFields::of($cup, $board->now), 'rules' => CupFields::rules($cup)],
            'standings' => $board->standings,
            'me' => $mine === null ? null : [
                'participant_id' => $mine->id,
                'display_name' => $mine->display_name,
                'status' => $mine->status->value,
                'first_day' => $mine->first_day,
                'renames_left' => max(0, CupParticipationService::MAX_RENAMES - $mine->rename_count),
                'total' => $mine->best_total,
                'final_rank' => $mine->final_rank,
                'today' => $board->today,
                'days' => $board->days ?? [],
            ],
            'can_manage' => $board->canManage,
            'server_now' => IsoTime::format($board->now),
        ];
    }
}
