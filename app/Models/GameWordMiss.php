<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameWordMissFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The mistakes of the game per word (docs/18 §12.2.4): the input of the rule of docs/18 §12.4 and of the pick of a boss. Written only by GameSrsAdjuster.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $word_id
 * @property int $misses
 * @property CarbonImmutable $last_missed_at
 * @property CarbonImmutable|null $last_pulled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameWordMiss extends Model
{
    /** @use HasFactory<GameWordMissFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'word_id',
        'misses',
        'last_missed_at',
        'last_pulled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'word_id' => 'integer',
            'misses' => 'integer',
            'last_missed_at' => 'immutable_datetime',
            'last_pulled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Word, $this> */
    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }
}
