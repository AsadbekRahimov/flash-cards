<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Practice\Enums\BattleRole;
use Carbon\CarbonImmutable;
use Database\Factories\BattleParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the two players of a battle (docs/16 §2.7). `active_learner_id` equals `learner_id` while the player is busy
 * with the match (the challenger from the invitation, the opponent from the acceptance) and is NULL after any end:
 * UNIQUE, so "one active battle per learner" is a database fact. `last_seen_at` is the heartbeat (written at most
 * once per `heartbeat_write_seconds`); `locale` is the language of the player's side of the snapshot.
 *
 * @property int $id
 * @property int $battle_id
 * @property int $learner_id
 * @property BattleRole $role
 * @property int|null $active_learner_id
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $surrendered_at
 * @property int $score
 * @property string $locale
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class BattleParticipant extends Model
{
    /** @use HasFactory<BattleParticipantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'battle_id',
        'learner_id',
        'role',
        'active_learner_id',
        'ready_at',
        'last_seen_at',
        'surrendered_at',
        'score',
        'locale',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'battle_id' => 'integer',
            'learner_id' => 'integer',
            'role' => BattleRole::class,
            'active_learner_id' => 'integer',
            'ready_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'surrendered_at' => 'immutable_datetime',
            'score' => 'integer',
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
