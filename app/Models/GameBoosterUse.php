<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameBoosterUseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a booster in a run (docs/18 §12.2.6): the base of the weekly limit (`week_start` is the Monday of the learner's local week).
 *
 * @property int $id
 * @property int $learner_id
 * @property int $run_id
 * @property int $item_id
 * @property string|null $element_id
 * @property CarbonImmutable $used_at
 * @property CarbonImmutable $week_start
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameBoosterUse extends Model
{
    /** @use HasFactory<GameBoosterUseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'run_id',
        'item_id',
        'element_id',
        'used_at',
        'week_start',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'run_id' => 'integer',
            'item_id' => 'integer',
            'used_at' => 'immutable_datetime',
            'week_start' => 'immutable_date',
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

    /** @return BelongsTo<GameItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GameItem::class, 'item_id');
    }
}
