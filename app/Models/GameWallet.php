<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The wallet of coins (docs/18 §12.2.5): one row per learner, created lazily; `balance` changes only under FOR UPDATE, together with a row of the ledger.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $balance
 * @property int $earned_total
 * @property int $spent_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameWallet extends Model
{
    /** @use HasFactory<GameWalletFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'balance',
        'earned_total',
        'spent_total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'balance' => 'integer',
            'earned_total' => 'integer',
            'spent_total' => 'integer',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
