<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/** `game_runs.free_slot` (docs/18 §12.2.1): what paid for a map run of a Free learner - the free run of the day or a ticket. */
enum FreeSlot: string
{
    case Daily = 'daily';
    case Ticket = 'ticket';
}
