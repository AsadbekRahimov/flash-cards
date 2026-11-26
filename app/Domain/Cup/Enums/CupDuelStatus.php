<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * `cup_duels.status` (docs/33 §2.9, §5.7): `open` (a challenge waits for an answer) → `accepted` (the match is on) → `done` (scored) /
 * `void` (the match never really happened: the slots are free again); an open challenge ends as `declined`, `expired` or `canceled`.
 */
enum CupDuelStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Done = 'done';
    case Void = 'void';

    public function isTerminal(): bool
    {
        return $this !== self::Open && $this !== self::Accepted;
    }

    /** The challenge never became a match: the public message says «Вызов не состоялся». */
    public function fellThrough(): bool
    {
        return in_array($this, [self::Declined, self::Expired, self::Canceled, self::Void], true);
    }
}
