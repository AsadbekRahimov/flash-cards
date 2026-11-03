<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The result of a move of tickets (docs/18 §12.7.3): `balance` after it; `delta` - what was actually written to the ledger (a grant
 * cut by the cap writes less than asked, a grant at the cap writes nothing); `lost` - what the cap cut off; `applied` - false when
 * the idempotency key had been written before (nothing changed, the balance is the one after the first write) or nothing was to be
 * written.
 */
final readonly class TicketGrant
{
    public function __construct(
        public int $balance,
        public int $delta,
        public int $lost,
        public bool $applied,
    ) {}
}
