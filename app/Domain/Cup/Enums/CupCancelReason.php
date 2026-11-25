<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/** `cups.cancel_reason` (docs/33 §2.2). */
enum CupCancelReason: string
{
    case DraftExpired = 'draft_expired';
    case ChatAdmin = 'chat_admin';
    case PlatformAdmin = 'platform_admin';
    case NoPlayers = 'no_players';
}
