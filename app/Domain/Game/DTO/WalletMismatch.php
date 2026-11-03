<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * A wallet whose `balance` is not the sum of its ledger (docs/18 §12.2.5), as `game:wallets-verify` reports it. `balance` is null for
 * a learner who has entries in the ledger but no wallet row at all.
 */
final readonly class WalletMismatch
{
    public function __construct(
        public int $learnerId,
        public ?int $balance,
        public int $ledgerTotal,
    ) {}

    /** @return array{learner_id: int, balance: int|null, ledger_total: int} */
    public function toArray(): array
    {
        return ['learner_id' => $this->learnerId, 'balance' => $this->balance, 'ledger_total' => $this->ledgerTotal];
    }
}
