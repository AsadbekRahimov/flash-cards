<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\InventorySource;
use Carbon\CarbonImmutable;
use Database\Factories\GameInventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a learner owns (docs/18 §12.2.6): a booster is used up (`qty`), a cosmetic is always 1.
 *
 * @property int $id
 * @property int $learner_id
 * @property int $item_id
 * @property int $qty
 * @property CarbonImmutable $acquired_at
 * @property InventorySource $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GameInventoryItem extends Model
{
    /** @use HasFactory<GameInventoryItemFactory> */
    use HasFactory;

    protected $table = 'game_inventory';

    /** @var list<string> */
    protected $fillable = [
        'learner_id',
        'item_id',
        'qty',
        'acquired_at',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learner_id' => 'integer',
            'item_id' => 'integer',
            'qty' => 'integer',
            'acquired_at' => 'immutable_datetime',
            'source' => InventorySource::class,
        ];
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<GameItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GameItem::class, 'item_id');
    }
}
