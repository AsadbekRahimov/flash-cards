<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

/**
 * Why the ticket of a run came back (docs/18 §12.7.3; `reason` of `game.ticket_refunded`): the run ended without a single answer -
 * replaced by a start with `replace_active` (`replaced`), left by the learner (`exited`), or run out of time (`expired`: the scheduler,
 * a lazy close on a read, an answer or an exit after `expires_at`, a start that found it dead).
 */
enum TicketRefundReason: string
{
    case Replaced = 'replaced';
    case Exited = 'exited';
    case Expired = 'expired';
}
