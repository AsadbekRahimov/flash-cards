<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Game\Enums\ItemStatus;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Services\Economy\ShopService;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Services\GameFriendsCache;
use App\Domain\Game\Services\GameShopCache;
use App\Domain\Game\Services\MapService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\GameItem;
use App\Models\GameStopProgress;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The cache of the game (docs/18 §12.12, docs/19 §2.2); the shop is at the end of the file. `game:friends:v2:{learner}`: the friends on the map - a pure cache of arrays with a
 * TTL, dropped for BOTH sides when a friendship is accepted, ended or blocked. Each way that writes has a test of the kind "write, and the
 * old value is not served"; the progress of a friend is allowed to be up to the TTL old (the last test says so).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    config(['game.access' => 'all', 'telegram.bot_username' => 'LexiFlowBot']);
    Queue::fake();
    PureCache::store()->flush();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{0: Course, 1: Lesson} */
function gameCacheCourse(): array
{
    $course = Course::factory()->published()->create(['slug' => 'silk']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
    PracticeKit::words($lesson, 2);

    return [$course, $lesson];
}

/** A learner who cleared the stop. */
function gameCacheFriend(Lesson $lesson, string $name, int $stars = 2): Learner
{
    $friend = Learner::factory()->create(['first_name' => $name]);
    GameStopProgress::factory()->cleared($stars)->create(['learner_id' => $friend->id, 'lesson_id' => $lesson->id]);

    return $friend;
}

/** @return list<string> the names of the friends on the map of the learner */
function gameCacheNames(Learner $learner): array
{
    return array_column(app(MapService::class)->map($learner, 'silk')->friends, 'name');
}

/** @return list<string> the statements of one map that read friendships */
function gameCacheFriendStatements(Learner $learner): array
{
    return QueryLog::matching(QueryLog::during(fn () => app(MapService::class)->map($learner, 'silk')), '/friendships/');
}

it('serves the friends from the cache: the second map reads no friendships', function (): void {
    [, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    Friendship::factory()->between($me, gameCacheFriend($lesson, 'Dilnoza'))->create();

    expect(gameCacheFriendStatements($me))->toHaveCount(1)
        ->and(gameCacheFriendStatements($me))->toBe([])
        ->and(gameCacheNames($me))->toBe(['Dilnoza']);
});

it('stores arrays under a key with the version, for the TTL (docs/19 §3)', function (): void {
    config(['cache.pure_store' => 'array']); // the clock of the test moves the clock of an array store only
    [$course, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    $friend = gameCacheFriend($lesson, 'Dilnoza');
    $friendship = Friendship::factory()->between($me, $friend)->create();
    gameCacheNames($me);

    $stored = PureCache::store()->get("game:friends:v2:{$me->id}");

    expect(GameFriendsCache::key($me->id))->toBe("game:friends:v2:{$me->id}")
        ->and($stored)->toBe([[
            'friendship_id' => $friendship->id, 'learner_id' => $friend->id, 'course_id' => $course->id,
            'city_number' => 1, 'stops_cleared' => 1, 'camel_asset' => null,
        ]]);

    // 300 s: gone after the TTL
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(GameFriendsCache::TTL_SECONDS + 1));
    expect(PureCache::store()->has(GameFriendsCache::key($me->id)))->toBeFalse();
});

it('keeps the cache of a learner apart from the cache of another', function (): void {
    [, $lesson] = gameCacheCourse();
    $anna = Learner::factory()->create();
    $boris = Learner::factory()->create();
    Friendship::factory()->between($anna, gameCacheFriend($lesson, 'Dilnoza'))->create();
    Friendship::factory()->between($boris, gameCacheFriend($lesson, 'Aziz'))->create();

    expect(gameCacheNames($anna))->toBe(['Dilnoza'])
        ->and(gameCacheNames($boris))->toBe(['Aziz'])
        ->and(PureCache::store()->has(GameFriendsCache::key($anna->id)))->toBeTrue()
        ->and(PureCache::store()->has(GameFriendsCache::key($boris->id)))->toBeTrue();

    // dropping the key of one does not touch the other
    GameFriendsCache::forget($anna->id);
    expect(PureCache::store()->has(GameFriendsCache::key($anna->id)))->toBeFalse()
        ->and(PureCache::store()->has(GameFriendsCache::key($boris->id)))->toBeTrue();
});

it('drops the cache of both sides when a request is accepted: the new friend is on the map at once', function (): void {
    [, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    $friend = gameCacheFriend($lesson, 'Dilnoza');
    $friends = app(FriendshipService::class);
    $request = $friends->request($friend, $me);

    // both maps are read (and cached) while the request is pending
    expect(gameCacheNames($me))->toBe([])->and(gameCacheNames($friend))->toBe([]);

    $friends->accept($me, $request->id);

    expect(gameCacheNames($me))->toBe(['Dilnoza']);
    expect(PureCache::store()->has(GameFriendsCache::key($friend->id)))->toBeFalse();
});

it('drops the cache of both sides when a friend is removed, in either direction', function (): void {
    [, $lesson] = gameCacheCourse();
    $friends = app(FriendshipService::class);

    foreach ([true, false] as $byMe) {
        $me = Learner::factory()->create();
        $friend = gameCacheFriend($lesson, 'Dilnoza');
        $friendship = Friendship::factory()->between($me, $friend)->create();
        expect(gameCacheNames($me))->toBe(['Dilnoza']);
        gameCacheNames($friend);

        $friends->remove($byMe ? $me : $friend, $friendship->id);

        expect(gameCacheNames($me))->toBe([])
            ->and(PureCache::store()->has(GameFriendsCache::key($friend->id)))->toBeFalse();
    }
});

it('drops the cache of both sides when a friend is blocked', function (): void {
    [, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    $friend = gameCacheFriend($lesson, 'Dilnoza');
    Friendship::factory()->between($me, $friend)->create();
    expect(gameCacheNames($me))->toBe(['Dilnoza']);
    gameCacheNames($friend);

    app(FriendshipService::class)->block($me, $friend);

    expect(gameCacheNames($me))->toBe([])
        ->and(PureCache::store()->has(GameFriendsCache::key($friend->id)))->toBeFalse();
});

it('shows no friend after a request was declined', function (): void {
    [, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    $friends = app(FriendshipService::class);
    $request = $friends->request(gameCacheFriend($lesson, 'Dilnoza'), $me);
    gameCacheNames($me);

    $friends->decline($me, $request->id);

    expect(gameCacheNames($me))->toBe([]);
});

it('lets the progress of a friend be up to the TTL old, and shows the new one after it', function (): void {
    [, $lesson] = gameCacheCourse();
    $me = Learner::factory()->create();
    $friend = gameCacheFriend($lesson, 'Dilnoza');
    Friendship::factory()->between($me, $friend)->create();
    expect(app(MapService::class)->map($me, 'silk')->friends[0]['stops_cleared'])->toBe(1);

    $second = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    PracticeKit::words($second, 2);
    GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $second->id]);

    expect(app(MapService::class)->map($me, 'silk')->friends[0]['stops_cleared'])->toBe(1);

    PureCache::store()->forget(GameFriendsCache::key($me->id)); // what the TTL does
    expect(app(MapService::class)->map($me, 'silk')->friends[0]['stops_cleared'])->toBe(2);
});

/*
 * `game:shop:v1:{locale}:n{version}`: the published catalogue of the shop as arrays, 1800 s; the version `game:shop:ver` lives on the default
 * store and is moved on after the commit of any save / delete of an item (GameItemObserver). Balance, inventory and what is worn are never cached.
 */

/** @return list<string> the statements of the catalogue read in one request of the shop */
function gameCacheShopStatements(Learner $learner): array
{
    return QueryLog::matching(QueryLog::during(fn () => app(ShopService::class)->shop($learner)), '/game_items/');
}

it('serves the catalogue of the shop from the cache: the second read touches no item', function (): void {
    GameItem::factory()->count(3)->create();
    $learner = Learner::factory()->create();

    expect(gameCacheShopStatements($learner))->toHaveCount(1)
        ->and(gameCacheShopStatements($learner))->toBe([])
        ->and(app(ShopService::class)->shop($learner)->items)->toHaveCount(3);
});

it('stores arrays of scalars under a key with the version, in a key of its own for each locale, for the TTL', function (): void {
    config(['cache.pure_store' => 'array']); // the clock of the test moves the clock of an array store only
    $item = GameItem::factory()->create(['key' => 'camel_x', 'title' => ['ru' => 'Икс', 'uz' => 'Iks']]);
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    app(ShopService::class)->shop($ru);
    app(ShopService::class)->shop($uz);

    $version = (int) Cache::get(GameShopCache::VERSION_KEY, 0);
    $stored = PureCache::store()->get("game:shop:v1:ru:n{$version}");

    expect(GameShopCache::key('ru'))->toBe("game:shop:v1:ru:n{$version}")
        ->and(GameShopCache::key('uz'))->toBe("game:shop:v1:uz:n{$version}")
        ->and($stored)->toBe([[
            'id' => $item->id, 'key' => 'camel_x', 'kind' => 'cosmetic', 'slot' => 'camel', 'effect' => null, 'price' => 50,
            'availability' => 'base', 'title' => 'Икс', 'description' => null, 'asset' => null,
        ]])
        ->and(PureCache::store()->get(GameShopCache::key('uz'))[0]['title'])->toBe('Iks');

    // 1800 s: gone after the TTL
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(GameShopCache::TTL_SECONDS + 1));
    expect(PureCache::store()->has(GameShopCache::key('ru')))->toBeFalse();
});

it('does not serve the old catalogue after an item is saved, created or deleted', function (): void {
    $item = GameItem::factory()->create(['key' => 'camel_x', 'title' => ['ru' => 'Старое', 'uz' => 'Eski'], 'price_coins' => 50]);
    $learner = Learner::factory()->create();
    $read = fn (): array => app(ShopService::class)->shop($learner)->items;
    expect($read()[0])->toMatchArray(['title' => 'Старое', 'price' => 50]);
    $before = (int) Cache::get(GameShopCache::VERSION_KEY, 0);

    $item->update(['title' => ['ru' => 'Новое', 'uz' => 'Yangi'], 'price_coins' => 70]);
    expect((int) Cache::get(GameShopCache::VERSION_KEY, 0))->toBeGreaterThan($before)
        ->and($read()[0])->toMatchArray(['title' => 'Новое', 'price' => 70]);

    GameItem::factory()->create(['key' => 'camel_y']);
    expect(array_column($read(), 'key'))->toBe(['camel_x', 'camel_y']);

    $item->update(['status' => ItemStatus::Archived->value]);
    expect(array_column($read(), 'key'))->toBe(['camel_y']);

    GameItem::query()->where('key', 'camel_y')->firstOrFail()->delete();
    expect($read())->toBe([]);
});

it('never caches what is the learner\'s own: a purchase shows at once while the catalogue is warm', function (): void {
    GameItem::factory()->booster()->create(['key' => 'booster_x', 'price_coins' => 10]);
    $learner = Learner::factory()->create();
    DB::transaction(fn () => app(WalletService::class)->credit($learner->id, 30, LedgerKind::AdminGrant, "admin:seed:{$learner->id}"));
    app(ShopService::class)->shop($learner); // warm

    app(ShopService::class)->buy($learner, 'booster_x', (string) Str::uuid());
    $shop = app(ShopService::class)->shop($learner);

    expect($shop->balance)->toBe(20)
        ->and($shop->items[0])->toMatchArray(['owned' => true, 'qty' => 1])
        ->and(PureCache::store()->get(GameShopCache::key('ru'))[0])->not->toHaveKeys(['owned', 'qty', 'equipped', 'balance']);
});
