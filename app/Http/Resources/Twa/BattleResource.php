<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Practice\DTO\BattleView;
use App\Domain\Practice\Support\IsoTime;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * A battle as one of its players sees it (docs/16 §7.4): GET /practice/battles/{id}, the 201 of the invitation, the answer of
 * every action (accept, decline, cancel, ready, surrender), the `battle` of audio-failed. One form for every status, from the
 * invitation to the result. The dates are the server's, ISO 8601 UTC with milliseconds; the client counts down to them by
 * `server_now`.
 *
 * A white list over {@see BattleView}: the snapshot never gets this far. The two keys the document leaves to the "Implementation
 * 18.6" notes: `audio_preload` (the URLs of the five clips of a dictation, room / countdown only: the very `audio_url` the rounds
 * give, so the blob loaded in the room is the blob of the round) and `friendship_id` (a rematch is offered without it).
 *
 * @property BattleView $resource
 */
final class BattleResource extends TwaResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'id' => $view->id,
            'status' => $view->status,
            'end_reason' => $view->endReason,
            'mode' => $view->mode,
            'role' => $view->role,
            'course' => $view->course,
            'lesson' => $view->lesson,
            'opponent' => $view->opponent,
            'rounds_total' => $view->roundsTotal,
            'round_seconds' => $view->roundSeconds,
            'countdown_seconds' => $view->countdownSeconds,
            'reveal_seconds' => $view->revealSeconds,
            'presence_grace_seconds' => $view->presenceGraceSeconds,
            'presence_warn_seconds' => $view->presenceWarnSeconds,
            'deadline_grace_ms' => $view->deadlineGraceMs,
            'invite_expires_at' => self::iso($view->inviteExpiresAt),
            'room_expires_at' => self::iso($view->roomExpiresAt),
            'starts_at' => self::iso($view->startsAt),
            'version' => $view->version,
            'server_now' => self::iso($view->serverNow),
            'ready' => $view->ready,
            'opponent_present' => $view->opponentPresent,
            'opponent_absent_since' => self::iso($view->opponentAbsentSince),
            'round' => $view->round?->toArray(),
            'scores' => $view->scores,
            'result' => $view->result,
            'rematch' => $view->rematch,
            'friendship_id' => $view->friendshipId,
            'audio_preload' => $view->audioPreload,
        ];
    }

    private static function iso(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : IsoTime::format($time);
    }
}
