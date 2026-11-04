<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Observers\GameItemObserver;
use Carbon\CarbonImmutable;
use Database\Factories\GameItemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item of the catalogue of the shop (docs/18 §12.2.6): a cosmetic (a `slot`) or a booster (an `effect`). `title` and `description` are `{ru, uz}`; `asset` is a name from the whitelist of the Mini App, not a URL.
 *
 * @property int $id
 * @property string $key
 * @property ItemKind $kind
 * @property ItemSlot|null $slot
 * @property BoosterEffect|null $effect
 * @property int $price_coins
 * @property ItemAvailability $availability
 * @property string|null $achievement_key
 * @property int|null $season_id
 * @property ItemStatus $status
 * @property int $sort
 * @property array<string, string> $title
 * @property array<string, string>|null $description
 * @property string|null $asset
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[ObservedBy(GameItemObserver::class)]
class GameItem extends Model
{
    /** @use HasFactory<GameItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'kind',
        'slot',
        'effect',
        'price_coins',
        'availability',
        'achievement_key',
        'season_id',
        'status',
        'sort',
        'title',
        'description',
        'asset',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ItemKind::class,
            'slot' => ItemSlot::class,
            'effect' => BoosterEffect::class,
            'price_coins' => 'integer',
            'availability' => ItemAvailability::class,
            'season_id' => 'integer',
            'status' => ItemStatus::class,
            'sort' => 'integer',
            'title' => 'array',
            'description' => 'array',
        ];
    }

    /** @return HasMany<GameInventoryItem, $this> */
    public function inventory(): HasMany
    {
        return $this->hasMany(GameInventoryItem::class, 'item_id');
    }
}
