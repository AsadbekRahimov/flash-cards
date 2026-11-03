<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Game\DTO\WalletDebit;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Exceptions\GameException;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameWallet;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The wallet of coins (docs/18 §12.2.5, §12.5.1 rule 20): `game_wallets.balance` under `FOR UPDATE` plus the append-only ledger with
 * a unique `idempotency_key`. G2.2a brings {@see debit()} - the hint of the bazaar pays with it; `credit()`, the invariant command
 * `game:wallets-verify` and the concurrency suite come with G2.5a.
 *
 * Call inside the transaction of the caller, AFTER the row of the run (the wallet is never taken first; nothing holding it waits for a
 * run). One wallet per transaction, the learner's own. The row is made lazily: `insertOrIgnore` then the read under the lock.
 */
final class WalletService
{
    /**
     * Takes `$amount` coins once per `$idempotencyKey`: a second call with the same key writes nothing and answers the balance after the
     * first (`applied = false`). Not enough coins: 409 `coins_insufficient` and nothing written (the caller returns it after its commit).
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws GameException coins_insufficient {balance, price}
     */
    public function debit(int $learnerId, int $amount, LedgerKind $kind, string $idempotencyKey, ?string $refType = null, ?int $refId = null, array $meta = []): WalletDebit
    {
        if ($amount <= 0) {
            throw new LogicException('A debit takes a positive amount.');
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A debit must run inside the transaction of its caller.');
        }

        $wallet = $this->lockedWallet($learnerId);

        /** @var GameCoinLedgerEntry|null $prior */
        $prior = GameCoinLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior !== null) {
            return new WalletDebit($prior->balance_after, false);
        }

        if ($wallet->balance < $amount) {
            throw GameException::coinsInsufficient($wallet->balance, $amount);
        }

        $balance = $wallet->balance - $amount;
        GameCoinLedgerEntry::query()->create([
            'learner_id' => $learnerId,
            'amount' => -$amount,
            'kind' => $kind,
            'idempotency_key' => $idempotencyKey,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'balance_after' => $balance,
            'meta' => $meta,
        ]);
        $wallet->forceFill(['balance' => $balance, 'spent_total' => $wallet->spent_total + $amount])->save();

        return new WalletDebit($balance, true);
    }

    /** The balance without a lock (0 for a learner who has no wallet yet). */
    public function balance(int $learnerId): int
    {
        return (int) GameWallet::query()->where('learner_id', $learnerId)->value('balance');
    }

    /** The learner's wallet under `FOR UPDATE`, made first when it is missing. */
    private function lockedWallet(int $learnerId): GameWallet
    {
        GameWallet::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'balance' => 0,
            'earned_total' => 0,
            'spent_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var GameWallet $wallet */
        $wallet = GameWallet::query()->where('learner_id', $learnerId)->lockForUpdate()->firstOrFail();

        return $wallet;
    }
}
