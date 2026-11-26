<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\Services\CupParticipationService;
use App\Http\Resources\Twa\TwaResource;
use App\Models\CupParticipant;
use Illuminate\Http\Request;

/**
 * `POST /cups/{id}/join` (201 new, 200 already in) and `PATCH /cups/{id}/me` (docs/04 A.23): the learner's own participation. Only the
 * participant id goes out - never the learner id or a Telegram id (docs/07 §10).
 *
 * @property CupParticipant $resource
 */
final class CupParticipantResource extends TwaResource
{
    /** @return array{participant_id: int, cup_id: int, display_name: string, status: string, first_day: int, renames_left: int} */
    public function toArray(Request $request): array
    {
        $participant = $this->resource;

        return [
            'participant_id' => $participant->id,
            'cup_id' => $participant->cup_id,
            'display_name' => $participant->display_name,
            'status' => $participant->status->value,
            'first_day' => $participant->first_day,
            'renames_left' => max(0, CupParticipationService::MAX_RENAMES - $participant->rename_count),
        ];
    }
}
