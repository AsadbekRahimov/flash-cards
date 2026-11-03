<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\LedgerKind;
use Carbon\CarbonImmutable;
use Database\Factories\GameCoinLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the append-only ledger of coins (docs/18 §12.2.5): `SUM(amount)` of a learner equals `game_wallets.balance`. `idempotency_key` is unique - a second entry with the same key is impossible. No `updated_at`.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $amount
 * @property LedgerKind $kind
 * @property string $idempotency_key
 * @property string|null $ref_type
 * @property int|null $ref_id
 * @property int $balance_after
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 */
class GameCoinLedgerEntry extends Model
{
    /** @use HasFactory<GameCoinLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'game_coin_ledger';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'amount',
        'kind',
        'idempotency_key',
        'ref_type',
        'ref_id',
        'balance_after',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'amount' => 'integer',
            'kind' => LedgerKind::class,
            'ref_id' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
