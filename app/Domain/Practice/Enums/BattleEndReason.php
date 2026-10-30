<?php

declare(strict_types=1);

namespace App\Domain\Practice\Enums;

/**
 * Why a battle ended (`battles.end_reason`, docs/16 §2.7, §8.1). For a `finished` battle: completed, surrender,
 * absent. For a `closed` one: declined, invite_expired, room_expired, canceled, both_absent, voided.
 */
enum BattleEndReason: string
{
    case Completed = 'completed';
    case Surrender = 'surrender';
    case Absent = 'absent';
    case Declined = 'declined';
    case InviteExpired = 'invite_expired';
    case RoomExpired = 'room_expired';
    case Canceled = 'canceled';
    case BothAbsent = 'both_absent';
    case Voided = 'voided';

    /** The reasons of a `finished` battle (a result exists); the others close it without one. */
    public function hasResult(): bool
    {
        return match ($this) {
            self::Completed, self::Surrender, self::Absent => true,
            default => false,
        };
    }
}
