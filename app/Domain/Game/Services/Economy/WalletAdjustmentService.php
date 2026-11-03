<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Game\DTO\WalletEntry;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Exceptions\GameException;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A manual move of a learner's coins by an admin (docs/18 §12.10). The audit record `game.coins_adjusted` is written FIRST, in a
 * transaction of its own; then the entry of the ledger follows in another one with the key `admin:{audit_log_id}`, so every manual
 * entry has its record and a repeat of the same record can not move the coins twice. A refused debit (not enough coins) leaves the
 * record, marked `rejected`, and no entry: the balance never goes below zero ({@see WalletService::debit()}).
 */
final class WalletAdjustmentService
{
    public const ACTION = 'game.coins_adjusted';

    /** The most an admin moves in one go. */
    public const MAX_AMOUNT = 100000;

    public const MAX_REASON = 200;

    public function __construct(private readonly WalletService $wallet) {}

    /**
     * @param  int  $amount  coins, positive; `$credit` says the direction
     *
     * @throws GameException coins_insufficient {balance, price}
     */
    public function adjust(Learner $learner, User $actor, int $amount, string $reason, bool $credit, ?string $ip = null): WalletEntry
    {
        $auditMeta = ['direction' => $credit ? 'credit' : 'debit', 'amount' => $amount, 'reason' => $reason];
        $audit = DB::transaction(fn (): AuditLog => AuditLog::create([
            'user_id' => $actor->id,
            'action' => self::ACTION,
            'entity_type' => Learner::class,
            'entity_id' => $learner->id,
            'ip' => $ip,
            'meta' => $auditMeta,
        ]));

        $key = 'admin:'.$audit->id;
        $meta = ['audit_log_id' => $audit->id, 'reason' => $reason];

        try {
            return DB::transaction(fn (): WalletEntry => $credit
                ? $this->wallet->credit($learner->id, $amount, LedgerKind::AdminGrant, $key, 'audit_log', $audit->id, $meta)
                : $this->wallet->debit($learner->id, $amount, LedgerKind::AdminRevoke, $key, 'audit_log', $audit->id, $meta));
        } catch (GameException $e) {
            $audit->update(['meta' => [...$auditMeta, 'rejected' => $e->errorCode]]);

            throw $e;
        }
    }
}
