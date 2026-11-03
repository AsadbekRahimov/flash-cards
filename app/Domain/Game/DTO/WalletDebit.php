<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The result of `WalletService::debit()`: the balance after the entry, and whether this call wrote it (`false`: the idempotency
 * key was in the ledger already - the balance is the one of that first entry, nothing was taken twice).
 */
final readonly class WalletDebit
{
    public function __construct(
        public int $balance,
        public bool $applied,
    ) {}
}
