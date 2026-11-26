<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cup_participants.status` (docs/33 §2.3): `left` - Telegram told us, or a fresh check found, that the learner is not in the chat. */
enum CupParticipantStatus: string
{
    case Active = 'active';
    case Left = 'left';
}
