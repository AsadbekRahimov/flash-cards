<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\DTO\ShopView;
use App\Domain\Game\DTO\WalletEntry;
use App\Domain\Game\Enums\InventorySource;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\GameAccess;
use App\Domain\Game\Services\GameShopCache;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The shop, the inventory and the wardrobe of the game "Caravan" (docs/18 §12.2.6, §12.5.1, §12.8, §12.15).
 *
 * THE CATALOGUE is the published items as arrays in one locale, a pure cache with the version in the key ({@see GameShopCache}); the
 * learner's own state (what he owns, wears, can buy) is laid over it on every read and is never cached.
 *
 * A PURCHASE is one transaction in the order of §12.5.1: `game_wallets FOR UPDATE` -> `game_coin_ledger` INSERT ({@see WalletService::debit()},
 * key `shop:{learner}:{request_id}`, so a repeated request pays once and answers the same) -> `game_inventory` (a cosmetic once, a booster
 * `qty + 1`); the events follow the commit. The price is read from the row, never from the cache. The rules: only a published item;
 * `achievement` and an item for 0 are not for sale; `exclusive` / `seasonal` need the feature `game.all_bosses` at this moment (what was
 * bought stays after Pro ends); a cosmetic is bought once. Coins cannot be bought (docs/18 §12.15).
 *
 * THE ITEMS BY DEFAULT (a cosmetic, `base`, price 0) belong to everybody who opens the game: they are owned VIRTUALLY - no row is written on
 * a read, a late-added one reaches everybody - and the row (`source = default`) is made when the learner first puts it on.
 *
 * Wearing: only a cosmetic of that slot that he owns; `item_key = null` takes the slot off. The profile row is locked for it (alone: a
 * run's finish touches the same row with one UPDATE and waits for nothing else).
 */
final class ShopService
{
    /** `details.reason` of `item_not_purchasable`. */
    public const REASON_PRO = 'pro';

    public const REASON_ACHIEVEMENT = 'achievement';

    public const REASON_NOT_FOR_SALE = 'not_for_sale';

    public function __construct(
        private readonly GameAccess $access,
        private readonly WalletService $wallet,
        private readonly EntitlementResolver $entitlements,
        private readonly LocaleResolver $locales,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The published catalogue in the locale, as arrays (the cached shape, docs/19 §3: no objects).
     *
     * @return list<array{id: int, key: string, kind: string, slot: string|null, effect: string|null, price: int, availability: string, title: string, description: string|null, asset: string|null}>
     */
    public function catalog(string $locale): array
    {
        // The key (with the version) is made BEFORE the rows are read.
        /** @var list<array{id: int, key: string, kind: string, slot: string|null, effect: string|null, price: int, availability: string, title: string, description: string|null, asset: string|null}> */
        return PureCache::store()->remember(
            GameShopCache::key($locale),
            GameShopCache::TTL_SECONDS,
            fn (): array => $this->buildCatalog($locale),
        );
    }

    /**
     * @throws GameException game_unavailable (403)
     */
    public function shop(Learner $learner): ShopView
    {
        $this->access->assertAvailable($learner);

        $catalog = $this->catalog($this->locales->forLearner($learner));
        /** @var array<int, int> $owned item id => qty */
        $owned = GameInventoryItem::query()->where('learner_id', $learner->id)->pluck('qty', 'item_id')->map(static fn (mixed $qty): int => (int) $qty)->all();
        $state = DB::table('learners')
            ->leftJoin('game_wallets', 'game_wallets.learner_id', '=', 'learners.id')
            ->leftJoin('game_profiles', 'game_profiles.learner_id', '=', 'learners.id')
            ->where('learners.id', $learner->id)
            ->first(['game_wallets.balance', 'game_profiles.equipped']);
        $worn = array_flip(WalletOverview::decodeEquipped($state->equipped ?? null));

        // The plan is read once, and only when something not owned needs it.
        $pro = null;
        $hasPro = function () use ($learner, &$pro): bool {
            return $pro ??= $this->entitlements->for($learner)->can('game.all_bosses');
        };

        $items = [];
        foreach ($catalog as $item) {
            $availability = ItemAvailability::from($item['availability']);
            $qty = $owned[$item['id']] ?? 0;
            $isOwned = $qty > 0 || self::isDefault($item['kind'], $availability, $item['price']);

            $lock = null;
            if (! $isOwned) {
                if ($availability === ItemAvailability::Achievement) {
                    $lock = 'achievement';
                } elseif ($availability->needsPro() && ! $hasPro()) {
                    $lock = 'pro';
                }
            }

            $items[] = [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'slot' => $item['slot'],
                'effect' => $item['effect'],
                'price' => $item['price'],
                'availability' => $item['availability'],
                'owned' => $isOwned,
                'qty' => $item['kind'] === ItemKind::Cosmetic->value ? ($isOwned ? 1 : 0) : $qty,
                'equipped' => isset($worn[$item['id']]),
                'locked_reason' => $lock,
                'title' => $item['title'],
                'description' => $item['description'],
                'asset' => $item['asset'],
            ];
        }

        return new ShopView($items, (int) ($state->balance ?? 0));
    }

    /**
     * Buys one item once per `$requestId`.
     *
     * @throws GameException game_unavailable (403) | item_not_found (404) | item_not_purchasable {reason} · item_already_owned · coins_insufficient {balance, price} · request_id_reused (409)
     */
    public function buy(Learner $learner, string $key, string $requestId): WalletEntry
    {
        $this->access->assertAvailable($learner);

        $item = GameItem::query()->where('key', $key)->where('status', ItemStatus::Published->value)->first()
            ?? throw GameException::itemNotFound();

        // A repeat of the request is the same answer, whatever the item has become since (and a request id of another item is a bug).
        $ledgerKey = "shop:{$learner->id}:{$requestId}";
        $prior = GameCoinLedgerEntry::query()->where('idempotency_key', $ledgerKey)->first();
        if ($prior !== null) {
            if ($prior->ref_type !== 'game_item' || $prior->ref_id !== $item->id) {
                throw GameException::requestIdReused();
            }

            return new WalletEntry($prior->balance_after, false);
        }

        if ($item->kind === ItemKind::Cosmetic && GameInventoryItem::query()->where('learner_id', $learner->id)->where('item_id', $item->id)->exists()) {
            throw GameException::itemAlreadyOwned();
        }
        $this->assertForSale($learner, $item);

        return DB::transaction(function () use ($learner, $item, $ledgerKey): WalletEntry {
            $entry = $this->wallet->debit($learner->id, $item->price_coins, LedgerKind::ItemPurchase, $ledgerKey, 'game_item', $item->id, ['item_key' => $item->key]);
            if ($entry->applied) {
                $this->grant($learner->id, $item);
                DB::afterCommit(function () use ($learner, $item, $entry): void {
                    $this->events->record(ProductEventName::GameItemBought, ['item_key' => $item->key, 'kind' => $item->kind->value, 'price' => $item->price_coins], learner: $learner);
                    $this->events->record(ProductEventName::GameCoinsSpent, [
                        'amount' => $item->price_coins, 'kind' => LedgerKind::ItemPurchase->value, 'ref_type' => 'game_item', 'ref_id' => $item->id, 'balance' => $entry->balance,
                    ], learner: $learner);
                });
            }

            return $entry;
        });
    }

    /**
     * Puts a cosmetic of the slot on, or takes the slot off (`$itemKey = null`).
     *
     * @throws GameException game_unavailable (403) | item_not_found (404): no such cosmetic of that slot among the items he owns
     */
    public function equip(Learner $learner, ItemSlot $slot, ?string $itemKey): void
    {
        $this->access->assertAvailable($learner);

        $item = null;
        if ($itemKey !== null) {
            $item = GameItem::query()->where('key', $itemKey)->where('kind', ItemKind::Cosmetic->value)->where('slot', $slot->value)->first()
                ?? throw GameException::itemNotFound();
        }

        DB::transaction(function () use ($learner, $slot, $item): void {
            $profile = $this->lockedProfile($learner->id);

            if ($item !== null) {
                $this->assertWearable($learner->id, $item);
            }

            $equipped = $profile->equipped;
            if ($item === null) {
                unset($equipped[$slot->value]);
            } else {
                $equipped[$slot->value] = $item->id;
            }
            $profile->forceFill(['equipped' => $equipped])->save();

            DB::afterCommit(fn () => $this->events->record(ProductEventName::GameCosmeticEquipped, ['slot' => $slot->value, 'item_key' => $item?->key], learner: $learner));
        });
    }

    /** Whether an item of that shape is given to everybody (owned without a row). */
    public static function isDefault(string $kind, ItemAvailability $availability, int $price): bool
    {
        return $kind === ItemKind::Cosmetic->value && $availability === ItemAvailability::Base && $price === 0;
    }

    /**
     * @throws GameException item_not_purchasable {reason}
     */
    private function assertForSale(Learner $learner, GameItem $item): void
    {
        if ($item->availability === ItemAvailability::Achievement) {
            throw GameException::itemNotPurchasable(self::REASON_ACHIEVEMENT);
        }
        if ($item->price_coins <= 0) {
            throw GameException::itemNotPurchasable(self::REASON_NOT_FOR_SALE);
        }
        if ($item->availability->needsPro() && ! $this->entitlements->for($learner)->can('game.all_bosses')) {
            throw GameException::itemNotPurchasable(self::REASON_PRO);
        }
    }

    /**
     * The inventory row of a purchase, inside the transaction and after the wallet. A cosmetic that appeared meanwhile (a parallel
     * purchase of another request) fails the whole transaction - the coins go back with the rollback; a booster is `qty + 1`.
     *
     * @throws GameException item_already_owned
     */
    private function grant(int $learnerId, GameItem $item): void
    {
        $now = CarbonImmutable::now();
        $inserted = GameInventoryItem::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'item_id' => $item->id,
            'qty' => 1,
            'acquired_at' => $now,
            'source' => InventorySource::Purchase->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted > 0) {
            return;
        }
        if ($item->kind === ItemKind::Cosmetic) {
            throw GameException::itemAlreadyOwned();
        }

        GameInventoryItem::query()->where('learner_id', $learnerId)->where('item_id', $item->id)->increment('qty');
    }

    /**
     * @throws GameException item_not_found
     */
    private function assertWearable(int $learnerId, GameItem $item): void
    {
        if (GameInventoryItem::query()->where('learner_id', $learnerId)->where('item_id', $item->id)->exists()) {
            return;
        }
        if ($item->status !== ItemStatus::Published || ! self::isDefault($item->kind->value, $item->availability, $item->price_coins)) {
            throw GameException::itemNotFound();
        }

        // A default item: the row is made now, once.
        $now = CarbonImmutable::now();
        GameInventoryItem::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'item_id' => $item->id,
            'qty' => 1,
            'acquired_at' => $now,
            'source' => InventorySource::Default->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The profile of the learner under `FOR UPDATE`, made first when it is missing. */
    private function lockedProfile(int $learnerId): GameProfile
    {
        $profile = GameProfile::query()->where('learner_id', $learnerId)->lockForUpdate()->first();
        if ($profile !== null) {
            return $profile;
        }

        $now = CarbonImmutable::now();
        GameProfile::query()->insertOrIgnore(['learner_id' => $learnerId, 'created_at' => $now, 'updated_at' => $now]);

        return GameProfile::query()->where('learner_id', $learnerId)->lockForUpdate()->first()
            ?? throw new RuntimeException("The profile of learner {$learnerId} vanished after its insert.");
    }

    /** @return list<array{id: int, key: string, kind: string, slot: string|null, effect: string|null, price: int, availability: string, title: string, description: string|null, asset: string|null}> */
    private function buildCatalog(string $locale): array
    {
        $catalog = [];
        foreach (GameItem::query()->where('status', ItemStatus::Published->value)->orderBy('sort')->orderBy('id')->get() as $item) {
            $catalog[] = [
                'id' => $item->id,
                'key' => $item->key,
                'kind' => $item->kind->value,
                'slot' => $item->slot?->value,
                'effect' => $item->effect?->value,
                'price' => $item->price_coins,
                'availability' => $item->availability->value,
                'title' => $this->text($item->title, $locale) ?? $item->key,
                'description' => $this->text($item->description, $locale),
                'asset' => $item->asset,
            ];
        }

        return $catalog;
    }

    /** @param array<string, string>|null $texts */
    private function text(?array $texts, string $locale): ?string
    {
        $text = $texts[$locale] ?? $texts['ru'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
