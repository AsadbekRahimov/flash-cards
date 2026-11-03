<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The game profile of a learner (docs/18 §12.2.6): one row, created lazily. `tickets` changes only under the lock of the learner's row. `equipped` is `{slot: item_id}`.
 *
 * @property int $id
 * @property int $learner_id
 * @property array<string, int> $equipped
 * @property int $tickets
 * @property int $tickets_earned_total
 * @property int $stops_cleared
 * @property int $cities_cleared
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameProfile extends Model
{
    /** @use HasFactory<GameProfileFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'equipped',
        'tickets',
        'tickets_earned_total',
        'stops_cleared',
        'cities_cleared',
        'last_run_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'equipped' => 'array',
            'tickets' => 'integer',
            'tickets_earned_total' => 'integer',
            'stops_cleared' => 'integer',
            'cities_cleared' => 'integer',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
