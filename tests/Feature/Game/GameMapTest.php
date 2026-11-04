<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\GameBoosterUse;
use App\Models\GameCityProgress;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.8, docs/04 A.18: GET /game/map - the cities (modules) and the stops (lessons) of a course, derived from the course. A stop is
 * open exactly when the learner has a `word_repetitions` row of an active word of the lesson. The cache of the friends is in GameCacheTest.
 */

uses(RefreshDatabase::class);

/** Budget of the map in SQL statements, `TwaAuth` (1) included (and the count of the limits of the learner, G2.5b part 2; and the card of the class caravan, G2.9b) - and it must not grow with the lessons and the friends. */
const BUDGET_MAP = 12;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    config(['game.access' => 'all']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function mapGet(Learner $learner, string $course = 'silk'): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/map?course={$course}");
}

/**
 * A published course of `$stages` modules with `$lessons` lessons each and `$words` active words in every lesson.
 *
 * @return array{0: Course, 1: list<list<Lesson>>} the lessons by module (0-based)
 */
function mapCourse(int $stages = 2, int $lessons = 2, int $words = 3, string $slug = 'silk'): array
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'title' => ['ru' => 'Шёлк', 'uz' => 'Ipak']]);
    $byStage = [];
    for ($s = 1; $s <= $stages; $s++) {
        $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => $s, 'title' => "Модуль {$s}", 'title_uz' => "Modul {$s}"]);
        for ($l = 1; $l <= $lessons; $l++) {
            $lesson = Lesson::factory()->for($stage)->create(['number' => $l, 'title' => "Урок {$s}.{$l}", 'title_uz' => "Dars {$s}.{$l}"]);
            PracticeKit::words($lesson, $words);
            $byStage[$s - 1][] = $lesson;
        }
    }

    return [$course, $byStage];
}

/** The learner has started the lesson: a word row for `$count` of its words. */
function mapStart(Learner $learner, Lesson $lesson, int $count = 1): void
{
    foreach (Word::query()->where('lesson_id', $lesson->id)->orderBy('id')->limit($count)->get() as $word) {
        PracticeKit::introduce($learner, $word);
    }
}

it('opens a stop exactly when the lesson has been started in the cards', function (): void {
    [, $lessons] = mapCourse(1, 3);
    $learner = Learner::factory()->create();
    mapStart($learner, $lessons[0][1]);

    $stops = mapGet($learner)->assertOk()->json('cities.0.stops');

    expect(array_column($stops, 'unlocked'))->toBe([false, true, false])
        ->and(mapGet($learner)->json('cities.0.unlocked'))->toBeTrue();

    // the next lesson is started: only then it opens
    mapStart($learner, $lessons[0][2]);
    expect(array_column(mapGet($learner)->json('cities.0.stops'), 'unlocked'))->toBe([false, true, true]);
});

it('does not open a stop for the repetitions of another learner, of an inactive word or of another lesson', function (): void {
    [, $lessons] = mapCourse(1, 3);
    $learner = Learner::factory()->create();
    mapStart(Learner::factory()->create(), $lessons[0][0]);
    $word = Word::query()->where('lesson_id', $lessons[0][1]->id)->firstOrFail();
    $word->update(['is_active' => false]);
    PracticeKit::introduce($learner, $word);

    $response = mapGet($learner)->assertOk();

    expect(array_column($response->json('cities.0.stops'), 'unlocked'))->toBe([false, false, false])
        ->and($response->json('cities.0.unlocked'))->toBeFalse();
});

it('leaves out the lessons without active words and the lessons of other courses', function (): void {
    [, $lessons] = mapCourse(1, 2);
    Word::query()->where('lesson_id', $lessons[0][1]->id)->update(['is_active' => false]);
    mapCourse(1, 4, 2, 'other');

    $stops = mapGet(Learner::factory()->create())->assertOk()->json('cities.0.stops');

    expect(array_column($stops, 'lesson_id'))->toBe([$lessons[0][0]->id]);
});

it('gives the stars, the score and the runs of the stop from game_stop_progress, zeros before the first run', function (): void {
    [, $lessons] = mapCourse(1, 2);
    $learner = Learner::factory()->create();
    mapStart($learner, $lessons[0][0]);
    GameStopProgress::factory()->cleared(2, 140)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][0]->id, 'runs_count' => 4]);
    GameStopProgress::factory()->cleared(3)->create(['lesson_id' => $lessons[0][1]->id]); // somebody else's

    $stops = mapGet($learner)->assertOk()->json('cities.0.stops');

    expect($stops[0])->toMatchArray(['lesson_id' => $lessons[0][0]->id, 'number' => 1, 'unlocked' => true, 'stars' => 2, 'best_score' => 140, 'runs' => 4])
        ->and($stops[1])->toMatchArray(['unlocked' => false, 'stars' => 0, 'best_score' => 0, 'runs' => 0]);
});

it('names the cities and the titles in the language of the learner: ru and uz', function (): void {
    mapCourse(2, 1);

    $ru = mapGet(Learner::factory()->create(['interface_locale' => 'ru']))->assertOk();
    $uz = mapGet(Learner::factory()->create(['interface_locale' => 'uz']))->assertOk();

    expect($ru->json('course'))->toBe(['slug' => 'silk', 'title' => 'Шёлк'])
        ->and($ru->json('cities.0.city'))->toBe(__('game.cities.1', [], 'ru'))
        ->and($ru->json('cities.1.city'))->toBe(__('game.cities.2', [], 'ru'))
        ->and($ru->json('cities.0.title'))->toBe('Модуль 1')
        ->and($ru->json('cities.0.stops.0.title'))->toBe('Урок 1.1')
        ->and($uz->json('course'))->toBe(['slug' => 'silk', 'title' => 'Ipak'])
        ->and($uz->json('cities.0.city'))->toBe(__('game.cities.1', [], 'uz'))
        ->and($uz->json('cities.0.title'))->toBe('Modul 1')
        ->and($uz->json('cities.0.stops.0.title'))->toBe('Dars 1.1')
        ->and($ru->json('cities.0.city'))->not->toBe($uz->json('cities.0.city'));
});

it('calls a city past the list "City N"', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'silk']);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 25]);
    PracticeKit::words(Lesson::factory()->for($stage)->create(['number' => 1]), 2);

    expect(mapGet(Learner::factory()->create())->assertOk()->json('cities.0.city'))->toBe(__('game.city_fallback', ['number' => 25], 'ru'));
});

it('states the boss: not ready, ready, defeated', function (): void {
    [, $lessons] = mapCourse(1, 4, 3);
    $learner = Learner::factory()->create();

    // 1 stop started (3 words): not enough words (8) and no cleared stops
    mapStart($learner, $lessons[0][0], 3);
    expect(mapGet($learner)->json('cities.0.boss'))->toBe([
        'state' => 'not_ready', 'defeated_at' => null, 'introduced' => 3, 'required' => 8, 'cleared_stops' => 0, 'required_stops' => 3,
    ]);

    // 3 stops started (9 words) but only two cleared: still not ready
    mapStart($learner, $lessons[0][1], 3);
    mapStart($learner, $lessons[0][2], 3);
    GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][0]->id]);
    GameStopProgress::factory()->cleared(3)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][1]->id]);
    GameStopProgress::factory()->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][2]->id, 'stars' => 0, 'runs_count' => 1]); // played, failed: 0 stars
    expect(mapGet($learner)->json('cities.0.boss'))->toMatchArray(['state' => 'not_ready', 'introduced' => 9, 'cleared_stops' => 2, 'required_stops' => 3]);

    GameStopProgress::query()->where('lesson_id', $lessons[0][2]->id)->update(['stars' => 1]);
    expect(mapGet($learner)->json('cities.0.boss'))->toMatchArray(['state' => 'ready', 'cleared_stops' => 3]);

    GameCityProgress::factory()->defeated()->create(['learner_id' => $learner->id, 'stage_id' => $lessons[0][0]->stage_id, 'boss_defeated_at' => '2026-11-04 08:30:15']);
    expect(mapGet($learner)->json('cities.0.boss'))->toMatchArray(['state' => 'defeated', 'defeated_at' => '2026-11-04T08:30:15.000Z']);
});

it('asks for fewer cleared stops when the city has fewer stops than the rule', function (): void {
    [, $lessons] = mapCourse(1, 2, 4);
    $learner = Learner::factory()->create();
    mapStart($learner, $lessons[0][0], 4);
    mapStart($learner, $lessons[0][1], 4);
    GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][0]->id]);
    GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[0][1]->id]);

    expect(mapGet($learner)->json('cities.0.boss'))->toMatchArray(['state' => 'ready', 'cleared_stops' => 2, 'required_stops' => 2]);
});

it('locks the bosses past the first city behind the plan for Free, and not for Pro; a defeated one stays defeated', function (): void {
    [, $lessons] = mapCourse(3, 1, 8);
    $free = Learner::factory()->create();
    $pro = PracticeKit::pro(Learner::factory()->create());
    foreach ([$free, $pro] as $learner) {
        foreach ([0, 1, 2] as $s) {
            mapStart($learner, $lessons[$s][0], 8);
            GameStopProgress::factory()->cleared(1)->create(['learner_id' => $learner->id, 'lesson_id' => $lessons[$s][0]->id]);
        }
    }
    GameCityProgress::factory()->defeated()->create(['learner_id' => $free->id, 'stage_id' => $lessons[2][0]->stage_id]);

    expect(array_column(array_column(mapGet($free)->json('cities'), 'boss'), 'state'))->toBe(['ready', 'locked_by_plan', 'defeated'])
        ->and(array_column(array_column(mapGet($pro)->json('cities'), 'boss'), 'state'))->toBe(['ready', 'ready', 'ready']);

    // the limit is config: none for Free / only Pro
    config(['game.free_limits.boss_stages' => null]);
    expect(mapGet($free)->json('cities.1.boss.state'))->toBe('ready');
    config(['game.free_limits.boss_stages' => 0]);
    expect(mapGet($free)->json('cities.0.boss.state'))->toBe('locked_by_plan');
});

it('gives the coins, the tickets and the totals of the learner, zeros before the first game', function (): void {
    mapCourse(1, 1);
    $learner = Learner::factory()->create();

    expect(mapGet($learner)->json('me'))->toBe(['stops_cleared' => 0, 'cities_cleared' => 0, 'coins' => 0, 'tickets' => 0]);

    GameWallet::factory()->withBalance(35)->create(['learner_id' => $learner->id]);
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id, 'stops_cleared' => 4, 'cities_cleared' => 1]);

    expect(mapGet($learner)->json('me'))->toBe(['stops_cleared' => 4, 'cities_cleared' => 1, 'coins' => 35, 'tickets' => 2]);
});

it('shows the live run, and not a finished or an expired one', function (): void {
    [$course] = mapCourse(1, 1);
    $learner = Learner::factory()->create();
    expect(mapGet($learner)->json('active_run'))->toBeNull();

    GameRun::factory()->finished()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);
    expect(mapGet($learner)->json('active_run'))->toBeNull();

    $run = GameRun::factory()->create(['learner_id' => $learner->id, 'course_id' => $course->id, 'answered' => 3, 'total' => 9]);
    expect(mapGet($learner)->json('active_run'))->toBe(['id' => $run->id, 'kind' => 'stop', 'progress' => ['answered' => 3, 'total' => 9]]);

    $run->update(['expires_at' => now()->subMinute()]);
    expect(mapGet($learner)->json('active_run'))->toBeNull();
});

it('states the daily caravan: the words due, done today, the next day', function (): void {
    [$course, $lessons] = mapCourse(1, 1, 3);
    $learner = Learner::factory()->create(['timezone' => 'UTC']);

    // nothing to repeat
    expect(mapGet($learner)->json('daily'))->toBe(['available' => false, 'done_today' => false, 'due_count' => 0, 'resets_at' => '2026-11-06T00:00:00.000Z']);

    // two words due
    foreach (Word::query()->where('lesson_id', $lessons[0][0]->id)->limit(2)->get() as $word) {
        PracticeKit::introduce($learner, $word, 'review')->update(['next_review_at' => now()->subHour()]);
    }
    expect(mapGet($learner)->json('daily'))->toMatchArray(['available' => true, 'done_today' => false, 'due_count' => 2]);

    // played today: spent, whatever the end was
    $daily = GameRun::factory()->daily()->finished()->create(['learner_id' => $learner->id, 'course_id' => $course->id, 'daily_key' => $learner->id.':2026-11-05', 'local_date' => '2026-11-05']);
    expect(mapGet($learner)->json('daily'))->toMatchArray(['available' => false, 'done_today' => true, 'due_count' => 2]);

    // yesterday's caravan does not count
    $daily->update(['daily_key' => $learner->id.':2026-11-04', 'local_date' => '2026-11-04']);
    expect(mapGet($learner)->json('daily'))->toMatchArray(['available' => true, 'done_today' => false]);
});

it('does not offer the daily caravan while a run is live', function (): void {
    [$course, $lessons] = mapCourse(1, 1, 1);
    $learner = Learner::factory()->create();
    PracticeKit::introduce($learner, Word::query()->firstOrFail(), 'review')->update(['next_review_at' => now()->subHour()]);
    GameRun::factory()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);

    expect(mapGet($learner)->json('daily'))->toMatchArray(['available' => false, 'done_today' => false, 'due_count' => 1]);
});

it('lists the accepted friends with the progress on this course, the most advanced first, and nobody else', function (): void {
    [$course, $lessons] = mapCourse(2, 2);
    [, $otherLessons] = mapCourse(1, 1, 2, 'other');
    $learner = Learner::factory()->create();
    $ahead = Learner::factory()->create(['first_name' => 'Dilnoza']);
    $behind = Learner::factory()->create(['first_name' => 'Aziz']);
    $pending = Learner::factory()->create();
    $stranger = Learner::factory()->create();
    $friendships = [
        $ahead->id => Friendship::factory()->between($learner, $ahead)->create(),
        $behind->id => Friendship::factory()->between($behind, $learner)->create(),
    ];
    Friendship::factory()->pending()->between($learner, $pending)->create();
    foreach ([[$ahead, 0, 0], [$ahead, 1, 0], [$ahead, 1, 1], [$behind, 0, 0], [$pending, 0, 0], [$stranger, 0, 0]] as [$who, $s, $l]) {
        GameStopProgress::factory()->cleared(2)->create(['learner_id' => $who->id, 'lesson_id' => $lessons[$s][$l]->id]);
    }
    GameStopProgress::factory()->cleared(2)->create(['learner_id' => $behind->id, 'lesson_id' => $otherLessons[0][0]->id]); // another course
    GameStopProgress::factory()->create(['learner_id' => $behind->id, 'lesson_id' => $lessons[1][0]->id, 'stars' => 0, 'runs_count' => 1]); // not cleared

    expect(mapGet($learner)->assertOk()->json('friends'))->toBe([
        ['friendship_id' => $friendships[$ahead->id]->id, 'name' => 'Dilnoza', 'city_number' => 2, 'stops_cleared' => 3, 'camel_asset' => null],
        ['friendship_id' => $friendships[$behind->id]->id, 'name' => 'Aziz', 'city_number' => 1, 'stops_cleared' => 1, 'camel_asset' => null],
    ]);
});

it('shows at most friends_max friends', function (): void {
    [, $lessons] = mapCourse(1, 1);
    config(['game.map.friends_max' => 3]);
    $learner = Learner::factory()->create();
    foreach (range(1, 5) as $i) {
        $friend = Learner::factory()->create();
        Friendship::factory()->between($learner, $friend)->create();
        GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $lessons[0][0]->id]);
    }

    expect(mapGet($learner)->json('friends'))->toHaveCount(3);
});

it('refuses with game_unavailable (403) when the game is off or the learner is not in the pilot', function (): void {
    mapCourse(1, 1);

    config(['game.access' => 'off']);
    mapGet(Learner::factory()->create(['practice_pilot_at' => now()]))->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');

    config(['game.access' => 'pilot']);
    mapGet(Learner::factory()->create())->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
    mapGet(Learner::factory()->create(['practice_pilot_at' => now()]))->assertOk();
});

it('answers 404 course_not_found for a missing, a draft and a private course of another learner', function (): void {
    Course::factory()->create(['slug' => 'draft']); // not published
    $private = Course::factory()->published()->deckOf(Learner::factory()->create()->id)->create(['slug' => 'secret']);
    $learner = Learner::factory()->create();

    foreach (['nope', 'draft', $private->slug] as $slug) {
        mapGet($learner, $slug)->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    }
});

it('validates the course parameter (422) and asks for the token (401)', function (): void {
    $learner = Learner::factory()->create();

    test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/map')->assertUnprocessable();
    test()->flushHeaders()->getJson('/api/twa/game/map?course=silk')->assertUnauthorized()->assertJsonPath('error.code', 'missing_token');
});

it('keeps the contract: the keys of the answer', function (): void {
    [, $lessons] = mapCourse(1, 1);
    mapStart($learner = Learner::factory()->create(), $lessons[0][0]);

    $json = mapGet($learner)->assertOk()->json();

    expect(array_keys($json))->toBe(['course', 'cities', 'daily', 'me', 'friends', 'active_run', 'class_caravan', 'limits'])
        ->and(array_keys($json['cities'][0]))->toBe(['stage_id', 'number', 'city', 'title', 'unlocked', 'stops', 'boss'])
        ->and(array_keys($json['cities'][0]['stops'][0]))->toBe(['lesson_id', 'number', 'title', 'unlocked', 'stars', 'best_score', 'runs'])
        ->and(array_keys($json['cities'][0]['boss']))->toBe(['state', 'defeated_at', 'introduced', 'required', 'cleared_stops', 'required_stops'])
        ->and(array_keys($json['daily']))->toBe(['available', 'done_today', 'due_count', 'resets_at'])
        ->and($json['class_caravan'])->toBeNull()
        ->and(array_keys($json['limits']))->toBe(['runs', 'tickets', 'boosters', 'boss_stages']);
});

it('does not write anything: the same map twice, no rows created', function (): void {
    [, $lessons] = mapCourse(1, 1);
    mapStart($learner = Learner::factory()->create(), $lessons[0][0]);
    $before = [GameWallet::query()->count(), GameProfile::query()->count(), GameRun::query()->count(), GameStopProgress::query()->count()];

    mapGet($learner)->assertOk();
    mapGet($learner)->assertOk();

    expect([GameWallet::query()->count(), GameProfile::query()->count(), GameRun::query()->count(), GameStopProgress::query()->count()])->toBe($before);
});

/** The statements of one map request, cold: every cache of the request is empty. */
function mapStatements(Learner $learner): int
{
    $headers = PracticeKit::headers($learner);
    cache()->flush();
    // The pure cache is a store of its own (docs/19 §4: `lru`, a second Redis in CI and production); flushing the
    // default store leaves it warm, and the second measurement would read the course and the friends from it.
    PureCache::store()->flush();
    app(EntitlementResolver::class)->forget($learner);

    return count(QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk()));
}

it('stays within the budget of statements, and the number does not grow with the lessons and the friends', function (): void {
    [, $lessons] = mapCourse(2, 2, 3);
    $learner = Learner::factory()->create();
    foreach ($lessons as $stage) {
        foreach ($stage as $lesson) {
            mapStart($learner, $lesson, 2);
        }
    }
    $friend = Learner::factory()->create();
    Friendship::factory()->between($learner, $friend)->create();
    GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $lessons[0][0]->id]);
    GameWallet::factory()->withBalance(5)->create(['learner_id' => $learner->id]);
    GameProfile::factory()->withTickets(1)->create(['learner_id' => $learner->id]);
    $small = mapStatements($learner);

    // 5 times the lessons, 9 friends with progress, a live run
    $course = Course::query()->where('slug', 'silk')->firstOrFail();
    foreach (range(3, 6) as $s) {
        $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => $s]);
        foreach (range(1, 5) as $l) {
            $lesson = Lesson::factory()->for($stage)->create(['number' => $l]);
            PracticeKit::words($lesson, 2);
            mapStart($learner, $lesson, 1);
        }
    }
    foreach (range(1, 8) as $i) {
        $friend = Learner::factory()->create();
        Friendship::factory()->between($learner, $friend)->create();
        GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $lessons[0][0]->id]);
    }
    GameRun::factory()->create(['learner_id' => $learner->id, 'course_id' => $course->id]);
    $big = mapStatements($learner);

    expect($small)->toBeLessThanOrEqual(BUDGET_MAP)
        ->and($big)->toBe($small);
});

it('reads no more than the friends cache holds when it is warm', function (): void {
    [, $lessons] = mapCourse(1, 1);
    $learner = Learner::factory()->create();
    $friend = Learner::factory()->create();
    Friendship::factory()->between($learner, $friend)->create();
    GameStopProgress::factory()->cleared()->create(['learner_id' => $friend->id, 'lesson_id' => $lessons[0][0]->id]);
    mapGet($learner)->assertOk();
    $headers = PracticeKit::headers($learner);

    $warm = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk());

    expect(QueryLog::matching($warm, '/friendships/'))->toBe([])->and(count($warm))->toBeLessThanOrEqual(BUDGET_MAP);
});

it('carries the limits of the learner (G2.8, G2.5b): the Free numbers with the runs and the boosters used, the tickets, null for what Pro does not have', function (): void {
    [, $lessons] = mapCourse(1, 1);
    GameKit::limits(1, 1, 1);
    $learner = Learner::factory()->create();
    mapStart($learner, $lessons[0][0]);
    GameProfile::factory()->withTickets(3)->create(['learner_id' => $learner->id]);
    $run = GameRun::factory()->create(['learner_id' => $learner->id, 'kind' => 'stop', 'status' => 'finished', 'finish_reason' => 'completed', 'answered' => 5, 'active_learner_id' => null, 'started_at' => CarbonImmutable::now()]);
    GameBoosterUse::factory()->create(['run_id' => $run->id, 'week_start' => '2026-11-02']);

    $limits = mapGet($learner)->assertOk()->json('limits');

    expect($limits)->toBe([
        'runs' => ['limit' => 1, 'used' => 1, 'remaining' => 0, 'resets_at' => '2026-11-05T19:00:00.000Z'],
        'tickets' => ['balance' => 3, 'max' => 10],
        'boosters' => ['limit' => 1, 'used' => 1, 'remaining' => 0, 'resets_at' => '2026-11-08T19:00:00.000Z'],
        'boss_stages' => 1,
    ]);

    PracticeKit::pro($learner);
    $pro = mapGet($learner)->assertOk()->json('limits');
    expect($pro['runs'])->toBeNull()->and($pro['boss_stages'])->toBeNull()->and($pro['boosters']['limit'])->toBe(5)->and($pro['tickets']['balance'])->toBe(3);
});
