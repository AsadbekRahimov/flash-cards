<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\Verdict;
use Carbon\CarbonImmutable;
use Database\Factories\BattleAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one final answer of a player to a round of a battle (docs/16 §2.7): `(battle_id, round, learner_id)` is unique;
 * `(battle_id, learner_id, request_id)` makes a repeated request find its row. `timeout` is written by the server at the
 * deadline of a round without an answer. The rows never change - there is no `updated_at`.
 *
 * @property int $id
 * @property int $battle_id
 * @property int $learner_id
 * @property int $round
 * @property string $request_id
 * @property array<string, mixed> $payload
 * @property Verdict $verdict
 * @property int $points
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $created_at
 */
class BattleAnswer extends Model
{
    /** @use HasFactory<BattleAnswerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'battle_id',
        'learner_id',
        'round',
        'request_id',
        'payload',
        'verdict',
        'points',
        'accepted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'battle_id' => 'integer',
            'learner_id' => 'integer',
            'round' => 'integer',
            'payload' => 'array',
            'verdict' => Verdict::class,
            'points' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Battle, $this> */
    public function battle(): BelongsTo
    {
        return $this->belongsTo(Battle::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
