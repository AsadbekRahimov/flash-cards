<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Game\DTO\WalletEntry;
use App\Domain\Game\DTO\WalletMismatch;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Exceptions\GameException;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameWallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The wallet of coins (docs/18 §12.2.5, §12.5.1 rule 20): `game_wallets.balance` under `FOR UPDATE` plus the append-only ledger with
 * a unique `idempotency_key`. Every move is one locked read of the wallet, one check of the key, one INSERT of the entry, one UPDATE
 * of the wallet - in that order, so that two moves of one wallet are serialised by its row and the second one sees the first one's
 * entry. `SUM(game_coin_ledger.amount) = balance` is the invariant; {@see mismatches()} is how `game:wallets-verify` checks it.
 *
 * Call inside the transaction of the caller, AFTER the row of the run (the wallet is never taken first; nothing holding it waits for a
 * run). One wallet per transaction, the learner's own. The row is made lazily: a locked read, then `insertOrIgnore` and the read
 * again when it is missing.
 */
final class WalletService
{
    /**
     * Gives `$amount` coins once per `$idempotencyKey`: a second call with the same key writes nothing and answers the balance after
     * the first (`applied = false`). `amount = 0` is a no-op without a lock (docs/18 §12.5.3: a reward of nothing is no entry).
     *
     * @param  array<string, mixed>  $meta
     */
    public function credit(int $learnerId, int $amount, LedgerKind $kind, string $idempotencyKey, ?string $refType = null, ?int $refId = null, array $meta = []): WalletEntry
    {
        if ($amount < 0) {
            throw new LogicException('A credit takes a non-negative amount.');
        }
        if ($amount === 0) {
            return new WalletEntry($this->balance($learnerId), false);
        }
        $this->assertInTransaction('credit');

        $wallet = $this->lockedWallet($learnerId);

        $prior = $this->priorEntry($learnerId, $idempotencyKey);
        if ($prior !== null) {
            return new WalletEntry($prior->balance_after, false);
        }

        $balance = $wallet->balance + $amount;
        $this->writeEntry($learnerId, $amount, $kind, $idempotencyKey, $refType, $refId, $meta, $balance);
        $wallet->forceFill(['balance' => $balance, 'earned_total' => $wallet->earned_total + $amount])->save();

        return new WalletEntry($balance, true);
    }

    /**
     * Takes `$amount` coins once per `$idempotencyKey`: a second call with the same key writes nothing and answers the balance after the
     * first (`applied = false`). Not enough coins: 409 `coins_insufficient` and nothing written (the caller returns it after its commit).
     * The shop (G2.5b) pays with it too: key `shop:{learner_id}:{request_id}`.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws GameException coins_insufficient {balance, price}
     */
    public function debit(int $learnerId, int $amount, LedgerKind $kind, string $idempotencyKey, ?string $refType = null, ?int $refId = null, array $meta = []): WalletEntry
    {
        if ($amount <= 0) {
            throw new LogicException('A debit takes a positive amount.');
        }
        $this->assertInTransaction('debit');

        $wallet = $this->lockedWallet($learnerId);

        $prior = $this->priorEntry($learnerId, $idempotencyKey);
        if ($prior !== null) {
            return new WalletEntry($prior->balance_after, false);
        }

        if ($wallet->balance < $amount) {
            throw GameException::coinsInsufficient($wallet->balance, $amount);
        }

        $balance = $wallet->balance - $amount;
        $this->writeEntry($learnerId, -$amount, $kind, $idempotencyKey, $refType, $refId, $meta, $balance);
        $wallet->forceFill(['balance' => $balance, 'spent_total' => $wallet->spent_total + $amount])->save();

        return new WalletEntry($balance, true);
    }

    /** The balance without a lock (0 for a learner who has no wallet yet). */
    public function balance(int $learnerId): int
    {
        return (int) GameWallet::query()->where('learner_id', $learnerId)->value('balance');
    }

    /**
     * Every wallet whose `balance` is not `SUM(amount)` of its ledger, and every learner with entries but no wallet row (docs/18
     * §12.2.5). Two set queries, no locks, nothing written: the report of `game:wallets-verify`.
     *
     * @return list<WalletMismatch>
     */
    public function mismatches(): array
    {
        $totals = DB::table('game_coin_ledger')->select('learner_id')->selectRaw('SUM(amount) AS ledger_total')->groupBy('learner_id');

        $found = [];
        $rows = DB::table('game_wallets AS w')
            ->leftJoinSub($totals, 'l', 'l.learner_id', '=', 'w.learner_id')
            ->select('w.learner_id', 'w.balance')
            ->selectRaw('COALESCE(l.ledger_total, 0) AS ledger_total')
            ->whereRaw('w.balance <> COALESCE(l.ledger_total, 0)')
            ->orderBy('w.learner_id')
            ->get();
        foreach ($rows as $row) {
            $found[] = new WalletMismatch((int) $row->learner_id, (int) $row->balance, (int) $row->ledger_total);
        }

        $orphans = DB::table('game_coin_ledger AS l')
            ->leftJoin('game_wallets AS w', 'w.learner_id', '=', 'l.learner_id')
            ->whereNull('w.id')
            ->select('l.learner_id')
            ->selectRaw('SUM(l.amount) AS ledger_total')
            ->groupBy('l.learner_id')
            ->orderBy('l.learner_id')
            ->get();
        foreach ($orphans as $row) {
            $found[] = new WalletMismatch((int) $row->learner_id, null, (int) $row->ledger_total);
        }

        return $found;
    }

    /** The learner's wallet under `FOR UPDATE`, made first when it is missing (one query when it exists, three the first time). */
    private function lockedWallet(int $learnerId): GameWallet
    {
        $wallet = GameWallet::query()->where('learner_id', $learnerId)->lockForUpdate()->first();
        if ($wallet !== null) {
            return $wallet;
        }

        $now = CarbonImmutable::now();
        GameWallet::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'balance' => 0,
            'earned_total' => 0,
            'spent_total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return GameWallet::query()->where('learner_id', $learnerId)->lockForUpdate()->first()
            ?? throw new RuntimeException("The wallet of learner {$learnerId} vanished after its insert.");
    }

    /**
     * The entry already written under this key, if any. A key that belongs to ANOTHER learner is a bug of the caller (the keys carry
     * the run / learner id), never a silent "already done".
     */
    private function priorEntry(int $learnerId, string $idempotencyKey): ?GameCoinLedgerEntry
    {
        $prior = GameCoinLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior !== null && $prior->learner_id !== $learnerId) {
            throw new LogicException("The ledger key {$idempotencyKey} belongs to learner {$prior->learner_id}, not {$learnerId}.");
        }

        return $prior;
    }

    /** @param array<string, mixed> $meta */
    private function writeEntry(int $learnerId, int $amount, LedgerKind $kind, string $idempotencyKey, ?string $refType, ?int $refId, array $meta, int $balanceAfter): void
    {
        GameCoinLedgerEntry::query()->create([
            'learner_id' => $learnerId,
            'amount' => $amount,
            'kind' => $kind,
            'idempotency_key' => $idempotencyKey,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'balance_after' => $balanceAfter,
            'meta' => $meta,
        ]);
    }

    private function assertInTransaction(string $what): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("A {$what} must run inside the transaction of its caller.");
        }
    }
}
