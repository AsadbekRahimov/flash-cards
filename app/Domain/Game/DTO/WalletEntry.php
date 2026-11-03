<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The result of `WalletService::credit()` / `debit()`: the balance after the entry, and whether this call wrote it (`false`: the
 * idempotency key was in the ledger already, or the amount was zero - the balance is the one of the wallet as it stands, nothing was
 * taken or given twice).
 */
final readonly class WalletEntry
{
    public function __construct(
        public int $balance,
        public bool $applied,
    ) {}
}
