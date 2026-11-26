<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Practice\Support\IsoTime;
use App\Http\Resources\Twa\TwaResource;
use App\Models\CupParticipant;
use Illuminate\Http\Request;

/**
 * `POST /cups/{id}/leave` (docs/04 A.23, docs/33 §11.12): the participant has left the cup for good. Only the ids go out - never the
 * learner id or a Telegram id (docs/07 §10). `left_at` - when he left the cup (ISO 8601 UTC); the screen goes to the list of cups.
 *
 * @property CupParticipant $resource
 */
final class CupLeaveResource extends TwaResource
{
    /** @return array{participant_id: int, cup_id: int, status: string, left: bool, left_at: string|null} */
    public function toArray(Request $request): array
    {
        $participant = $this->resource;

        return [
            'participant_id' => $participant->id,
            'cup_id' => $participant->cup_id,
            'status' => $participant->status->value,
            'left' => $participant->withdrawn_at !== null,
            'left_at' => $participant->withdrawn_at === null ? null : IsoTime::format($participant->withdrawn_at),
        ];
    }
}
