<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\WalletView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/wallet and the answer of POST /game/shop/{key}/buy (docs/18 §12.8, docs/04 A.18).
 *
 * @property WalletView $resource
 *
 * @phpstan-import-type LedgerLine from WalletView
 */
final class GameWalletResource extends TwaResource
{
    /**
     * @return array{balance: int, earned_total: int, spent_total: int, tickets: array{balance: int, max: int}, ledger: list<LedgerLine>}
     */
    public function toArray(Request $request): array
    {
        $wallet = $this->resource;

        return [
            'balance' => $wallet->balance,
            'earned_total' => $wallet->earnedTotal,
            'spent_total' => $wallet->spentTotal,
            'tickets' => $wallet->tickets,
            'ledger' => $wallet->ledger,
        ];
    }
}
