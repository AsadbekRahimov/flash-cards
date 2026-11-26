<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa\Cup;

use App\Domain\Cup\DTO\CupContext;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Support\DisplayName;
use App\Http\Resources\Twa\TwaResource;
use App\Models\Learner;
use Illuminate\Http\Request;

/**
 * `GET /cups/by-code/{code}` (docs/04 A.23): the invitation as a member of the chat sees it - the cup, its rules, the number of
 * participants and the learner's own place in it. `me.display_name` is his name in the cup, or the name he would join with (his
 * Telegram first name, cleaned). Nobody else's name is here.
 *
 * @property CupContext $resource
 */
final class CupInviteResource extends TwaResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $context = $this->resource;
        $cup = $context->cup;
        $mine = $context->participant;
        $learner = $request->attributes->get('learner');

        return [
            ...CupFields::of($cup),
            'participants_count' => (int) $context->participantsCount,
            'rules' => CupFields::rules($cup),
            'me' => [
                'joined' => $mine !== null,
                'participant_id' => $mine?->id,
                'status' => $mine?->status->value,
                'display_name' => $mine !== null
                    ? $mine->display_name
                    : ($learner instanceof Learner ? DisplayName::fromFirstName($learner->first_name, $learner->id) : null),
                'left' => $mine !== null && $mine->status === CupParticipantStatus::Left,
            ],
        ];
    }
}
