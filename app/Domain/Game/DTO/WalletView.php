<?php

declare(strict_types=1);

namespace App\Domain\Game\DTO;

/**
 * The wallet of a learner (docs/18 §12.8, docs/04 A.18): what `GameWalletResource` draws.
 *
 * @phpstan-type LedgerLine array{amount: int, kind: string, created_at: string, title: string}
 */
final readonly class WalletView
{
    /**
     * @param  array{balance: int, max: int}  $tickets
     * @param  list<LedgerLine>  $ledger
     */
    public function __construct(
        public int $balance,
        public int $earnedTotal,
        public int $spentTotal,
        public array $tickets,
        public array $ledger,
    ) {}
}
