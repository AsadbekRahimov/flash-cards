<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Game\Services\GameFriendsCache;
use App\Domain\Game\Services\MapService;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\GameStopProgress;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * The cache of the game (docs/18 §12.12, docs/19 §2.2). `game:friends:v1:{learner}`: the friends on the map - a pure cache of arrays with a
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

    $stored = PureCache::store()->get("game:friends:v1:{$me->id}");

    expect(GameFriendsCache::key($me->id))->toBe("game:friends:v1:{$me->id}")
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
