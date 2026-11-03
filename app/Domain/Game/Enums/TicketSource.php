<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_ticket_ledger.source` (docs/18 §12.2.6): where a ticket came from (`streak`, `referral`, `admin`) or went to (`spend`). */
enum TicketSource: string
{
    case Streak = 'streak';
    case Referral = 'referral';
    case Admin = 'admin';
    case Spend = 'spend';
}
