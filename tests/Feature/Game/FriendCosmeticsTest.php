<?php

declare(strict_types=1);

use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Services\Economy\WalletOverview;
use App\Domain\Game\Services\FriendCosmetics;
use App\Domain\Game\Services\GameFriendsCache;
use App\Domain\Game\Services\MapService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameStopProgress;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.8, docs/05 §5b.6 (G2.6): what the friends wear. The camel of a friend is the `asset` of `game_profiles.equipped.camel`, the
 * rule of `WalletOverview::profile` (only what is really worn, whatever the status of the item), read for ALL the friends in one statement;
 * `/friends` carries it only while the game is open to the viewer and touches no `game_*` table while it is closed.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    config(['game.access' => 'all', 'cache.pure_store' => 'array']);
    PureCache::store()->flush();
});

const COSMETICS_GAME_TABLE = '/\b(from|join|into|update)\s+["`]game_/i';

function cosmeticsCamel(string $key, ?string $asset, ItemStatus $status = ItemStatus::Published): GameItem
{
    return GameItem::factory()->cosmetic(ItemSlot::Camel)->create(['key' => $key, 'asset' => $asset, 'status' => $status]);
}

/** @param array<string, int> $equipped */
function cosmeticsWear(Learner $learner, array $equipped): void
{
    GameProfile::factory()->create(['learner_id' => $learner->id, 'equipped' => $equipped]);
}

/** @return array{0: Learner, 1: Lesson, 2: Course} */
function cosmeticsWorld(): array
{
    $course = Course::factory()->published()->create(['slug' => 'silk']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
    PracticeKit::words($lesson, 2);

    return [Learner::factory()->create(), $lesson, $course];
}

function cosmeticsFriend(Learner $me, string $name, ?Lesson $lesson = null): Learner
{
    $friend = Learner::factory()->create(['first_name' => $name]);
    Friendship::factory()->between($me, $friend)->create();
    if ($lesson !== null) {
        GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $lesson->id]);
    }

    return $friend;
}

it('reads the worn camel, null for no profile, an empty slot, a camel that is gone, a carpet in the camel slot; an archived camel stays', function (): void {
    $golden = cosmeticsCamel('camel_golden', 'camel_golden');
    $old = cosmeticsCamel('camel_old', 'camel_dromedary', ItemStatus::Archived);
    $carpet = GameItem::factory()->cosmetic(ItemSlot::Carpet)->create(['asset' => 'carpet_red']);
    [$a, $b, $c, $d, $e, $f] = Learner::factory()->count(6)->create()->all();
    cosmeticsWear($a, ['camel' => $golden->id, 'carpet' => $carpet->id]);
    cosmeticsWear($b, ['camel' => $old->id]);
    cosmeticsWear($c, ['carpet' => $carpet->id]);
    cosmeticsWear($d, []);
    cosmeticsWear($e, ['camel' => 999_999]);
    // $f has no profile row at all

    $camels = app(FriendCosmetics::class)->camelAssets([$a->id, $b->id, $c->id, $d->id, $e->id, $f->id]);

    expect($camels)->toBe([$a->id => 'camel_golden', $b->id => 'camel_dromedary', $c->id => null, $d->id => null, $e->id => null, $f->id => null]);
});

it('is the same answer as the own profile of the friend (one rule with WalletOverview)', function (): void {
    $archived = cosmeticsCamel('camel_old', 'camel_dromedary', ItemStatus::Archived);
    $worn = Learner::factory()->create();
    $bare = Learner::factory()->create();
    cosmeticsWear($worn, ['camel' => $archived->id]);

    $cosmetics = app(FriendCosmetics::class)->camelAssets([$worn->id, $bare->id]);

    expect($cosmetics[$worn->id])->toBe(app(WalletOverview::class)->profile($worn)->equipped['camel']['asset'])
        ->and($cosmetics[$bare->id])->toBeNull()
        ->and(app(WalletOverview::class)->profile($bare)->equipped)->toBe([]);
});

it('reads all the learners in one statement, however many', function (): void {
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    $ids = [];
    foreach (range(1, 12) as $i) {
        $learner = Learner::factory()->create();
        cosmeticsWear($learner, ['camel' => $camel->id]);
        $ids[] = $learner->id;
    }

    $log = QueryLog::during(fn () => app(FriendCosmetics::class)->camelAssets($ids));

    expect($log)->toHaveCount(1)
        ->and(app(FriendCosmetics::class)->camelAssets([]))->toBe([]);
});

it('puts the camel of the friend in the value of game:friends:v2 and on the map; null while no camel is worn', function (): void {
    [$me, $lesson] = cosmeticsWorld();
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    $dressed = cosmeticsFriend($me, 'Dilnoza', $lesson);
    $plain = cosmeticsFriend($me, 'Aziz', $lesson);
    cosmeticsWear($dressed, ['camel' => $camel->id]);

    $map = app(MapService::class)->map($me, 'silk');
    $stored = PureCache::store()->get(GameFriendsCache::key($me->id));

    expect(GameFriendsCache::key($me->id))->toBe("game:friends:v2:{$me->id}")
        ->and(array_column($stored, 'camel_asset', 'learner_id'))->toBe([$dressed->id => 'camel_golden', $plain->id => null])
        ->and(array_column($map->friends, 'camel_asset', 'name'))->toBe(['Dilnoza' => 'camel_golden', 'Aziz' => null]);
});

it('serves the camels out of the cache up to its TTL: no reset on a change of the camel', function (): void {
    [$me, $lesson] = cosmeticsWorld();
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    $friend = cosmeticsFriend($me, 'Dilnoza', $lesson);
    $map = fn (): array => array_column(app(MapService::class)->map($me, 'silk')->friends, 'camel_asset', 'name');
    expect($map())->toBe(['Dilnoza' => null]);

    cosmeticsWear($friend, ['camel' => $camel->id]);

    // the old value is served until the TTL (docs/18 §12.12)
    expect($map())->toBe(['Dilnoza' => null]);
    $this->travel(GameFriendsCache::TTL_SECONDS + 1)->seconds();
    expect($map())->toBe(['Dilnoza' => 'camel_golden']);
});

it('GET /friends carries camel_asset of each friend while the game is open, and null where no camel is worn', function (): void {
    [$me] = cosmeticsWorld();
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    $dressed = cosmeticsFriend($me, 'Dilnoza');
    cosmeticsFriend($me, 'Aziz');
    cosmeticsWear($dressed, ['camel' => $camel->id]);

    $response = $this->withHeaders(PracticeKit::headers($me))->getJson('/api/twa/friends')->assertOk();

    expect(array_column($response->json('friends'), 'camel_asset', 'first_name'))->toEqualCanonicalizing(['Dilnoza' => 'camel_golden', 'Aziz' => null])
        ->and(array_keys($response->json('friends.0')))->toBe(['id', 'first_name', 'streak', 'words_learned', 'since', 'camel_asset']);
});

it('GET /friends has no camel_asset and reads no game table while the game is closed; one statement more while it is open', function (): void {
    [$me] = cosmeticsWorld();
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    foreach (range(1, 6) as $i) {
        cosmeticsWear(cosmeticsFriend($me, "Friend {$i}"), ['camel' => $camel->id]);
    }
    $headers = PracticeKit::headers($me);
    $read = fn (): array => QueryLog::during(fn () => $this->withHeaders($headers)->getJson('/api/twa/friends')->assertOk());

    $read(); // warm: the words-learned cache
    config(['game.access' => 'off']);
    $closed = $read();
    $closedBody = $this->withHeaders($headers)->getJson('/api/twa/friends')->assertOk()->json();
    config(['game.access' => 'all']);
    $open = $read();

    expect(QueryLog::matching($closed, COSMETICS_GAME_TABLE))->toBe([])
        ->and($closedBody['friends'][0])->not->toHaveKey('camel_asset')
        ->and(QueryLog::matching($open, COSMETICS_GAME_TABLE))->toHaveCount(1)
        ->and(count($open) - count($closed))->toBe(1);
});

it('gives camels only to a viewer who is in the pilot', function (): void {
    config(['game.access' => 'pilot']);
    [$me] = cosmeticsWorld();
    cosmeticsFriend($me, 'Dilnoza');
    $headers = PracticeKit::headers($me);

    $this->withHeaders($headers)->getJson('/api/twa/friends')->assertOk()->assertJsonMissingPath('friends.0.camel_asset');

    $me->forceFill(['practice_pilot_at' => now()])->save();
    $this->withHeaders($headers)->getJson('/api/twa/friends')->assertOk()->assertJsonPath('friends.0.camel_asset', null);
});

it('answers accept with the same list, camels included', function (): void {
    [$me] = cosmeticsWorld();
    $camel = cosmeticsCamel('camel_golden', 'camel_golden');
    $asker = Learner::factory()->create(['first_name' => 'Bek']);
    $pending = Friendship::factory()->pending()->between($asker, $me, $asker)->create();
    cosmeticsWear($asker, ['camel' => $camel->id]);

    $this->withHeaders(PracticeKit::headers($me))->postJson("/api/twa/friends/{$pending->id}/accept")
        ->assertOk()
        ->assertJsonPath('friends.0.first_name', 'Bek')
        ->assertJsonPath('friends.0.camel_asset', 'camel_golden');
});
