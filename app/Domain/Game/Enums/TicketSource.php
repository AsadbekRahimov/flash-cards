<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/**
 * `game_ticket_ledger.source` (docs/18 §12.2.6): where a ticket came from (`streak`, `referral`, `admin`), went to (`spend`), or came
 * back from (`refund` - the spend of a run that ended without a single answer, docs/18 §12.7.3).
 */
enum TicketSource: string
{
    case Streak = 'streak';
    case Referral = 'referral';
    case Admin = 'admin';
    case Spend = 'spend';
    case Refund = 'refund';
}
