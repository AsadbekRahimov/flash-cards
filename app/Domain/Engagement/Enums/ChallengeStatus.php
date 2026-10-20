<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * State of a `challenges` row (docs/13 §1.5, §7.5). `pending` and `active` are the OPEN states (at most
 * one per pair of learners); the rest are final.
 */
enum ChallengeStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Finished = 'finished';
    case Declined = 'declined';
    /** Never accepted within `friends.challenges.pending_ttl_hours`. */
    case Expired = 'expired';
    /** The friendship ended (removed / blocked) while the challenge was open. */
    case Canceled = 'canceled';

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Pending->value, self::Active->value];
    }
}
