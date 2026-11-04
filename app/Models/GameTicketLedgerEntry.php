<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\TicketSource;
use Carbon\CarbonImmutable;
use Database\Factories\GameTicketLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the append-only ledger of tickets (docs/18 §12.2.6). `idempotency_key` is unique. No `updated_at`.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $delta
 * @property TicketSource $source
 * @property string $idempotency_key
 * @property int|null $run_id
 * @property CarbonImmutable|null $created_at
 */
class GameTicketLedgerEntry extends Model
{
    /** @use HasFactory<GameTicketLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'game_ticket_ledger';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'delta',
        'source',
        'idempotency_key',
        'run_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'delta' => 'integer',
            'source' => TicketSource::class,
            'run_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<GameRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(GameRun::class, 'run_id');
    }
}
