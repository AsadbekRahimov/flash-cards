<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\InventorySource;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Support\GameAssets;
use App\Models\GameCoinLedgerEntry;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\GameItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.2.6, §12.5.1, §12.8, §12.15 (G2.5b, part 1): the shop, the inventory and the wardrobe. The catalogue and its state for a
 * learner; a purchase (paid once per request id, the price from the row, a cosmetic once, a booster piles up, the refusals with their
 * codes and details); the items by default; putting on and taking off; the wallet and the profile. The invariant of the ledger is
 * checked after every purchase. The cache of the catalogue is in tests/Feature/Cache/GameCacheTest, the race in GameWalletConcurrencyTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A learner with `$coins` in a wallet that has the ledger to explain them. */
function shopLearner(int $coins = 0): Learner
{
    $learner = Learner::factory()->create();
    if ($coins > 0) {
        DB::transaction(fn () => app(WalletService::class)->credit($learner->id, $coins, LedgerKind::AdminGrant, "admin:seed:{$learner->id}"));
    }

    return $learner;
}

/** @return array<string, string> */
function shopHeaders(Learner $learner): array
{
    return PracticeKit::headers($learner);
}

function shopBuy(Learner $learner, string $key, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(shopHeaders($learner))->postJson("/api/twa/game/shop/{$key}/buy", ['request_id' => $requestId ?? (string) Str::uuid()]);
}

function shopEquip(Learner $learner, string $slot, ?string $key): TestResponse
{
    return test()->withHeaders(shopHeaders($learner))->postJson('/api/twa/game/profile/equip', ['slot' => $slot, 'item_key' => $key]);
}

/** @return array<string, mixed> the item of the shop by its key */
function shopItem(Learner $learner, string $key): array
{
    foreach (test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/shop')->assertOk()->json('items') as $item) {
        if ($item['key'] === $key) {
            return $item;
        }
    }

    throw new RuntimeException("No item {$key} in the shop.");
}

function shopInvariant(Learner $learner): void
{
    $balance = (int) GameWallet::query()->where('learner_id', $learner->id)->value('balance');
    expect((int) GameCoinLedgerEntry::query()->where('learner_id', $learner->id)->sum('amount'))->toBe($balance)
        ->and(app(WalletService::class)->mismatches())->toBe([]);
}

/** @return list<array<string, mixed>> */
function shopEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

it('seeds the base catalogue once: 3 camels, 2 carpets, 2 tents, 2 frames, 3 boosters, one free cosmetic in every slot, only whitelisted assets', function (): void {
    $this->seed(GameItemSeeder::class);
    $this->seed(GameItemSeeder::class);

    $items = GameItem::query()->get();
    $bySlot = $items->whereNotNull('slot')->groupBy(fn (GameItem $i): string => $i->slot->value)->map->count()->all();

    expect($items)->toHaveCount(12)
        ->and($bySlot)->toBe(['camel' => 3, 'carpet' => 2, 'tent' => 2, 'frame' => 2])
        ->and($items->whereNotNull('effect')->map(fn (GameItem $i): string => $i->effect->value)->sort()->values()->all())->toBe(['extra_flask', 'hint', 'second_chance'])
        ->and($items->where('status', '!=', ItemStatus::Published)->count())->toBe(0)
        ->and($items->where('price_coins', 0)->whereNotNull('slot')->map(fn (GameItem $i): string => $i->slot->value)->sort()->values()->all())->toBe(['camel', 'carpet', 'frame', 'tent'])
        ->and($items->every(fn (GameItem $i): bool => $i->asset !== null && in_array($i->asset, GameAssets::NAMES, true) && $i->title['ru'] !== '' && $i->title['uz'] !== ''))->toBeTrue();
});

it('does not undo an edit of the admin panel when the seeder runs again', function (): void {
    $this->seed(GameItemSeeder::class);
    GameItem::query()->where('key', 'camel_bactrian')->firstOrFail()->update(['price_coins' => 99]);

    $this->seed(GameItemSeeder::class);

    expect(GameItem::query()->where('key', 'camel_bactrian')->value('price_coins'))->toBe(99);
});

it('shows the catalogue with the state of the learner: the defaults are owned, the exclusive one is locked behind Pro, nothing is equipped', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(30);

    $response = test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/shop')->assertOk()
        ->assertJsonStructure(['items' => ['*' => ['key', 'kind', 'slot', 'effect', 'price', 'availability', 'owned', 'qty', 'equipped', 'locked_reason', 'title', 'description', 'asset']], 'balance'])
        ->assertJsonCount(12, 'items')
        ->assertJsonPath('balance', 30);
    $items = collect($response->json('items'))->keyBy('key');

    expect($items['camel_dromedary'])->toMatchArray(['kind' => 'cosmetic', 'slot' => 'camel', 'price' => 0, 'owned' => true, 'qty' => 1, 'equipped' => false, 'locked_reason' => null, 'title' => 'Дромедар'])
        ->and($items['camel_bactrian'])->toMatchArray(['price' => 150, 'owned' => false, 'qty' => 0, 'locked_reason' => null])
        ->and($items['camel_golden'])->toMatchArray(['availability' => 'exclusive', 'owned' => false, 'locked_reason' => 'pro'])
        ->and($items['booster_hint'])->toMatchArray(['kind' => 'booster', 'slot' => null, 'effect' => 'hint', 'qty' => 0, 'owned' => false])
        ->and(array_keys($response->json('items.0')))->toBe(['key', 'kind', 'slot', 'effect', 'price', 'availability', 'owned', 'qty', 'equipped', 'locked_reason', 'title', 'description', 'asset']);
    // no row was written by a read
    expect(GameInventoryItem::query()->count())->toBe(0);
});

it('speaks the language of the learner and falls back to Russian', function (): void {
    $this->seed(GameItemSeeder::class);
    GameItem::factory()->create(['key' => 'only_ru', 'title' => ['ru' => 'Только русский'], 'sort' => 1000]);
    $learner = Learner::factory()->create(['interface_locale' => 'uz']);

    $items = collect(test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/shop')->assertOk()->json('items'))->keyBy('key');

    expect($items['camel_dromedary']['title'])->toBe('Dromedar')
        ->and($items['only_ru']['title'])->toBe('Только русский');
});

it('buys a cosmetic: the coins go once, the ledger and the inventory say why, the events follow, the wallet is the answer', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);
    $item = GameItem::query()->where('key', 'camel_bactrian')->firstOrFail();

    shopBuy($learner, 'camel_bactrian', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')->assertOk()
        ->assertJsonPath('balance', 50)
        ->assertJsonPath('spent_total', 150)
        ->assertJsonPath('earned_total', 200)
        ->assertJsonPath('ledger.0.amount', -150)
        ->assertJsonPath('ledger.0.kind', 'item_purchase')
        ->assertJsonPath('ledger.0.title', 'Покупка в лавке');

    $entry = GameCoinLedgerEntry::query()->where('idempotency_key', "shop:{$learner->id}:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa")->firstOrFail();
    $row = GameInventoryItem::query()->where('learner_id', $learner->id)->firstOrFail();

    expect($entry->amount)->toBe(-150)->and($entry->balance_after)->toBe(50)->and($entry->ref_type)->toBe('game_item')->and($entry->ref_id)->toBe($item->id)
        ->and($entry->meta)->toBe(['item_key' => 'camel_bactrian'])
        ->and($row->item_id)->toBe($item->id)->and($row->qty)->toBe(1)->and($row->source)->toBe(InventorySource::Purchase)
        ->and(shopItem($learner, 'camel_bactrian'))->toMatchArray(['owned' => true, 'qty' => 1])
        ->and(shopEvents('game.item_bought'))->toEqual([['item_key' => 'camel_bactrian', 'kind' => 'cosmetic', 'price' => 150]])
        ->and(shopEvents('game.coins_spent'))->toEqual([['amount' => 150, 'kind' => 'item_purchase', 'ref_type' => 'game_item', 'ref_id' => $item->id, 'balance' => 50]]);
    shopInvariant($learner);
});

it('pays a repeated request once: the same answer, no second entry, no second event', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);
    $request = (string) Str::uuid();

    $first = shopBuy($learner, 'camel_bactrian', $request)->assertOk()->json();
    $again = shopBuy($learner, 'camel_bactrian', $request)->assertOk()->json();

    expect($again)->toBe($first)
        ->and(GameCoinLedgerEntry::query()->where('kind', LedgerKind::ItemPurchase->value)->count())->toBe(1)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(50)
        ->and(shopEvents('game.item_bought'))->toHaveCount(1)
        ->and(shopEvents('game.coins_spent'))->toHaveCount(1);
    shopInvariant($learner);
});

it('refuses a request id used for another item', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);
    $request = (string) Str::uuid();
    shopBuy($learner, 'booster_hint', $request)->assertOk();

    shopBuy($learner, 'booster_extra_flask', $request)->assertStatus(409)->assertJsonPath('error.code', 'request_id_reused');

    expect(GameInventoryItem::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('refuses a purchase the coins do not cover, with the balance and the price, and writes nothing', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(100);

    shopBuy($learner, 'camel_bactrian')->assertStatus(409)
        ->assertJsonPath('error.code', 'coins_insufficient')
        ->assertJsonPath('error.details', ['balance' => 100, 'price' => 150]);

    expect(GameInventoryItem::query()->count())->toBe(0)
        ->and(GameCoinLedgerEntry::query()->where('kind', LedgerKind::ItemPurchase->value)->count())->toBe(0)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(100)
        ->and(shopEvents('game.item_bought'))->toBe([]);

    // a learner with no wallet at all is refused the same way
    shopBuy(Learner::factory()->create(), 'booster_hint')->assertStatus(409)->assertJsonPath('error.details', ['balance' => 0, 'price' => 15]);
    shopInvariant($learner);
});

it('sells a cosmetic once: the second purchase is item_already_owned and costs nothing, even without the coins', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(150);
    shopBuy($learner, 'camel_bactrian')->assertOk();

    shopBuy($learner, 'camel_bactrian')->assertStatus(409)->assertJsonPath('error.code', 'item_already_owned');

    expect(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(0)
        ->and(GameCoinLedgerEntry::query()->where('kind', LedgerKind::ItemPurchase->value)->count())->toBe(1);
    shopInvariant($learner);
});

it('lets a booster pile up: qty + 1 per purchase', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(100);

    shopBuy($learner, 'booster_hint')->assertOk();
    shopBuy($learner, 'booster_hint')->assertOk();
    shopBuy($learner, 'booster_hint')->assertOk();

    expect(GameInventoryItem::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(GameInventoryItem::query()->where('learner_id', $learner->id)->value('qty'))->toBe(3)
        ->and(shopItem($learner, 'booster_hint'))->toMatchArray(['owned' => true, 'qty' => 3])
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(55);
    shopInvariant($learner);
});

it('does not sell what is not for sale, and says why', function (): void {
    $this->seed(GameItemSeeder::class);
    GameItem::factory()->create(['key' => 'camel_achieved', 'availability' => ItemAvailability::Achievement, 'achievement_key' => 'streak_7', 'price_coins' => 100]);
    GameItem::factory()->create(['key' => 'camel_gift', 'price_coins' => 0, 'availability' => ItemAvailability::Base, 'kind' => 'booster', 'slot' => null, 'effect' => BoosterEffect::Hint]);
    $learner = shopLearner(1000);

    shopBuy($learner, 'camel_achieved')->assertStatus(409)->assertJsonPath('error.code', 'item_not_purchasable')->assertJsonPath('error.details', ['reason' => 'achievement']);
    shopBuy($learner, 'camel_gift')->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'not_for_sale']);
    shopBuy($learner, 'camel_golden')->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'pro']);

    expect(shopItem($learner, 'camel_achieved'))->toMatchArray(['locked_reason' => 'achievement'])
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(1000)
        ->and(GameInventoryItem::query()->count())->toBe(0);
});

it('lets Pro buy the exclusive item, and keeps it (and lets it be worn) after Pro ends', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(500);
    $pro = PracticeKit::pro($learner);

    shopBuy($pro, 'camel_golden')->assertOk()->assertJsonPath('balance', 100);
    expect(shopItem($pro, 'camel_golden'))->toMatchArray(['owned' => true, 'locked_reason' => null]);

    // Pro ends: the owned item stays owned and can be put on; a new exclusive one is locked again
    DB::table('subscriptions')->delete();
    app(EntitlementResolver::class)->forget($learner);
    GameItem::factory()->exclusive()->create(['key' => 'camel_emir', 'price_coins' => 10]);

    expect(shopItem($learner, 'camel_golden'))->toMatchArray(['owned' => true, 'locked_reason' => null])
        ->and(shopItem($learner, 'camel_emir'))->toMatchArray(['locked_reason' => 'pro']);
    shopEquip($learner, 'camel', 'camel_golden')->assertOk()->assertJsonPath('equipped.camel.key', 'camel_golden');
    shopBuy($learner, 'camel_emir')->assertStatus(409)->assertJsonPath('error.details.reason', 'pro');
});

it('knows no item that is not published, nor one that does not exist', function (): void {
    $this->seed(GameItemSeeder::class);
    GameItem::factory()->draft()->create(['key' => 'camel_draft']);
    GameItem::factory()->create(['key' => 'camel_old', 'status' => ItemStatus::Archived]);
    $learner = shopLearner(1000);

    foreach (['camel_draft', 'camel_old', 'camel_nope'] as $key) {
        shopBuy($learner, $key)->assertNotFound()->assertJsonPath('error.code', 'item_not_found');
    }
    expect(collect(test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/shop')->json('items'))->pluck('key')->all())->not->toContain('camel_draft', 'camel_old');
});

it('validates the request id and the key of the route', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(100);

    test()->withHeaders(shopHeaders($learner))->postJson('/api/twa/game/shop/booster_hint/buy', [])->assertUnprocessable()->assertJsonValidationErrors('request_id');
    test()->withHeaders(shopHeaders($learner))->postJson('/api/twa/game/shop/booster_hint/buy', ['request_id' => 'not-a-uuid'])->assertUnprocessable();
    test()->withHeaders(shopHeaders($learner))->postJson('/api/twa/game/shop/Booster-Hint/buy', ['request_id' => (string) Str::uuid()])->assertNotFound();
    expect(GameInventoryItem::query()->count())->toBe(0);
});

it('puts a default item on (the row appears then, source default), replaces it with a bought one, and takes the slot off', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);

    // nothing worn: an object, not a list
    test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/profile')->assertOk()
        ->assertJson(['stops_cleared' => 0, 'cities_cleared' => 0, 'coins' => 200, 'tickets' => 0]);
    expect(test()->withHeaders(shopHeaders($learner))->get('/api/twa/game/profile')->getContent())->toContain('"equipped":{}');

    shopEquip($learner, 'camel', 'camel_dromedary')->assertOk()
        ->assertJsonPath('equipped.camel', ['key' => 'camel_dromedary', 'asset' => 'camel_dromedary']);
    expect(GameInventoryItem::query()->where('learner_id', $learner->id)->value('source'))->toBe(InventorySource::Default)
        ->and(shopItem($learner, 'camel_dromedary')['equipped'])->toBeTrue();

    shopBuy($learner, 'camel_bactrian')->assertOk();
    shopEquip($learner, 'camel', 'camel_bactrian')->assertOk()->assertJsonPath('equipped.camel.key', 'camel_bactrian');
    shopEquip($learner, 'frame', 'frame_plain')->assertOk();
    expect(shopItem($learner, 'camel_dromedary')['equipped'])->toBeFalse()
        ->and(shopItem($learner, 'camel_bactrian')['equipped'])->toBeTrue();

    $profile = test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/profile')->assertOk();
    expect(array_keys($profile->json('equipped')))->toEqualCanonicalizing(['camel', 'frame']);

    shopEquip($learner, 'camel', null)->assertOk()->assertJsonMissingPath('equipped.camel')->assertJsonPath('equipped.frame.key', 'frame_plain');
    expect(shopItem($learner, 'camel_bactrian')['equipped'])->toBeFalse()
        ->and(GameProfile::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(shopEvents('game.cosmetic_equipped'))->toEqual([
            ['slot' => 'camel', 'item_key' => 'camel_dromedary'],
            ['slot' => 'camel', 'item_key' => 'camel_bactrian'],
            ['slot' => 'frame', 'item_key' => 'frame_plain'],
            ['slot' => 'camel', 'item_key' => null],
        ]);

    // taking off an empty slot is not an error
    shopEquip($learner, 'tent', null)->assertOk();
});

it('puts on only what the learner owns, a cosmetic, of that slot', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);
    $other = shopLearner(200);
    shopBuy($other, 'camel_bactrian')->assertOk();

    shopEquip($learner, 'camel', 'camel_bactrian')->assertNotFound()->assertJsonPath('error.code', 'item_not_found'); // not bought
    shopEquip($other, 'carpet', 'camel_bactrian')->assertNotFound(); // the wrong slot
    shopBuy($other, 'booster_hint')->assertOk();
    shopEquip($other, 'camel', 'booster_hint')->assertNotFound(); // a booster is no cosmetic
    shopEquip($learner, 'camel', 'camel_nope')->assertNotFound();
    shopEquip($learner, 'tent', 'camel_golden')->assertNotFound();

    expect(GameProfile::query()->where('learner_id', $learner->id)->value('equipped'))->toBeNull()
        ->and(GameInventoryItem::query()->where('learner_id', $learner->id)->count())->toBe(0);
});

it('does not give the default of a price changed by the admin: a default item must be a free base cosmetic', function (): void {
    $this->seed(GameItemSeeder::class);
    GameItem::query()->where('key', 'carpet_plain')->update(['price_coins' => 5]);
    $learner = shopLearner();

    shopEquip($learner, 'carpet', 'carpet_plain')->assertNotFound();
    expect(shopItem($learner, 'carpet_plain'))->toMatchArray(['owned' => false, 'price' => 5]);
});

it('validates the equip request: the slot is one of four, the key is present', function (): void {
    $learner = shopLearner();
    $headers = shopHeaders($learner);

    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'hat', 'item_key' => null])->assertUnprocessable()->assertJsonValidationErrors('slot');
    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['item_key' => 'camel_dromedary'])->assertUnprocessable()->assertJsonValidationErrors('slot');
    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'camel'])->assertUnprocessable()->assertJsonValidationErrors('item_key');
    test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'camel', 'item_key' => ['x']])->assertUnprocessable();
    expect(array_column(ItemSlot::cases(), 'value'))->toBe(['camel', 'carpet', 'tent', 'frame']);
});

it('shows a worn item that was archived afterwards, and the counters of the profile', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(200);
    shopBuy($learner, 'tent_silk')->assertOk();
    shopEquip($learner, 'tent', 'tent_silk')->assertOk();
    GameProfile::query()->where('learner_id', $learner->id)->update(['stops_cleared' => 4, 'cities_cleared' => 1, 'tickets' => 2]);
    GameItem::query()->where('key', 'tent_silk')->update(['status' => ItemStatus::Archived->value]);

    test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/profile')->assertOk()
        ->assertExactJson(['equipped' => ['tent' => ['key' => 'tent_silk', 'asset' => 'tent_silk']], 'stops_cleared' => 4, 'cities_cleared' => 1, 'coins' => 100, 'tickets' => 2]);
});

it('keeps the inventory and the wardrobe of one learner from another', function (): void {
    $this->seed(GameItemSeeder::class);
    $anna = shopLearner(200);
    $boris = shopLearner(200);
    shopBuy($anna, 'camel_bactrian')->assertOk();
    shopEquip($anna, 'camel', 'camel_bactrian')->assertOk();

    expect(shopItem($boris, 'camel_bactrian'))->toMatchArray(['owned' => false, 'equipped' => false])
        ->and(test()->withHeaders(shopHeaders($boris))->getJson('/api/twa/game/profile')->json('equipped'))->toBe([])
        ->and(test()->withHeaders(shopHeaders($boris))->getJson('/api/twa/game/wallet')->json('ledger'))->toHaveCount(1) // his own credit only
        ->and(GameWallet::query()->where('learner_id', $boris->id)->value('balance'))->toBe(200);
});

it('answers the wallet: totals, tickets with their cap, the last 20 lines newest first, titles in the language of the learner', function (): void {
    $learner = shopLearner();
    GameProfile::factory()->withTickets(3)->create(['learner_id' => $learner->id]);
    foreach (range(1, 25) as $i) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 10:00:00')->addMinutes($i));
        DB::transaction(fn () => app(WalletService::class)->credit($learner->id, $i, $i % 2 === 0 ? LedgerKind::RunReward : LedgerKind::DailyReward, "test:{$learner->id}:{$i}"));
    }

    $response = test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/wallet')->assertOk()
        ->assertJsonStructure(['balance', 'earned_total', 'spent_total', 'tickets' => ['balance', 'max'], 'ledger' => ['*' => ['amount', 'kind', 'created_at', 'title']]])
        ->assertJsonCount(20, 'ledger')
        ->assertJsonPath('balance', 325)
        ->assertJsonPath('earned_total', 325)
        ->assertJsonPath('spent_total', 0)
        ->assertJsonPath('tickets', ['balance' => 3, 'max' => 10]);

    expect(array_column($response->json('ledger'), 'amount'))->toBe(range(25, 6))
        ->and($response->json('ledger.0'))->toBe(['amount' => 25, 'kind' => 'daily_reward', 'created_at' => '2026-11-05T10:25:00.000Z', 'title' => 'Награда за караван дня'])
        ->and($response->json('ledger.1.title'))->toBe('Награда за стоянку');

    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    DB::transaction(fn () => app(WalletService::class)->credit($uz->id, 5, LedgerKind::BossReward, "test:{$uz->id}:1"));
    test()->withHeaders(shopHeaders($uz))->getJson('/api/twa/game/wallet')->assertJsonPath('ledger.0.title', 'Boss uchun mukofot');
});

it('answers an empty wallet and an empty profile for a learner the game has not seen yet, and makes no row', function (): void {
    $learner = Learner::factory()->create();

    test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/wallet')->assertOk()
        ->assertExactJson(['balance' => 0, 'earned_total' => 0, 'spent_total' => 0, 'tickets' => ['balance' => 0, 'max' => 10], 'ledger' => []]);
    test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/profile')->assertOk();
    test()->withHeaders(shopHeaders($learner))->getJson('/api/twa/game/shop')->assertOk()->assertJsonPath('balance', 0);

    expect([GameWallet::query()->count(), GameProfile::query()->count(), GameInventoryItem::query()->count()])->toBe([0, 0, 0]);
});

it('is closed with game_unavailable when the game is off or the learner is not in the pilot, on every route, and writes nothing', function (): void {
    $this->seed(GameItemSeeder::class);
    $learner = shopLearner(500);

    foreach (['off', 'pilot'] as $access) {
        config(['game.access' => $access]);
        $headers = shopHeaders($learner);
        $responses = [
            test()->withHeaders($headers)->getJson('/api/twa/game/wallet'),
            test()->withHeaders($headers)->getJson('/api/twa/game/shop'),
            test()->withHeaders($headers)->postJson('/api/twa/game/shop/booster_hint/buy', ['request_id' => (string) Str::uuid()]),
            test()->withHeaders($headers)->getJson('/api/twa/game/profile'),
            test()->withHeaders($headers)->postJson('/api/twa/game/profile/equip', ['slot' => 'camel', 'item_key' => 'camel_dromedary']),
        ];
        foreach ($responses as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
        }
    }

    expect(GameInventoryItem::query()->count())->toBe(0)
        ->and(GameWallet::query()->where('learner_id', $learner->id)->value('balance'))->toBe(500);
});

it('wants a token', function (): void {
    test()->getJson('/api/twa/game/wallet')->assertUnauthorized();
    test()->getJson('/api/twa/game/shop')->assertUnauthorized();
    test()->postJson('/api/twa/game/shop/booster_hint/buy', ['request_id' => (string) Str::uuid()])->assertUnauthorized();
    test()->getJson('/api/twa/game/profile')->assertUnauthorized();
    test()->postJson('/api/twa/game/profile/equip', ['slot' => 'camel', 'item_key' => null])->assertUnauthorized();
});
