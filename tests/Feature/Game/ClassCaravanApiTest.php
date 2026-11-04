<?php

declare(strict_types=1);

use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\Course;
use App\Models\GameRun;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\ClassKit;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.8, §12.9, docs/04 A.18 (G2.9b): `GET /game/class`, `GET /game/class/{id}`, and the card `class_caravan` of the map and of
 * `/today`. Who sees what (the active members of the active group; a stranger's caravan and a canceled one are 404), the shape, the
 * table of the class with the first names only, the learner's own row, the fight with the boss of the class.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    config(['cache.pure_store' => 'array']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function classApiGet(Learner $learner, string $path = ''): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/game/class'.$path);
}

/** A caravan of the world's group, active by default. @param array<string, mixed> $attributes */
function classApiCaravan(array $world, array $attributes = []): ClassCaravan
{
    return ClassCaravan::factory()->create([
        'telegram_group_id' => $world['group']->id,
        'organization_id' => $world['organization']->id,
        'course_id' => $world['course']->id,
        'target_stars' => 18,
        ...$attributes,
    ]);
}

it('lists the caravans of the classes the learner is an active member of, the newest first, as short rows', function (): void {
    $w = ClassKit::world(2);
    [$me, $mate] = $w['learners'];
    $old = classApiCaravan($w, ['status' => ClassCaravanStatus::Finished, 'active_group_id' => null, 'starts_at' => now()->subDays(14), 'ends_at' => now()->subDays(7), 'finished_at' => now()->subDays(7), 'stars_total' => 20, 'runs_total' => 9]);
    $new = classApiCaravan($w, ['stars_total' => 5, 'runs_total' => 2]);
    classApiCaravan(ClassKit::world(1, chatId: -2002, teacherTelegramId: null), ['status' => ClassCaravanStatus::Active]); // another class
    classApiCaravan(ClassKit::world(1, chatId: -3003, teacherTelegramId: null), ['status' => ClassCaravanStatus::Canceled, 'active_group_id' => null]);

    $response = classApiGet($me)->assertOk();

    expect($response->json('caravans.*.id'))->toBe([$new->id, $old->id])
        ->and($response->json('caravans.0'))->toBe([
            'id' => $new->id,
            'group_title' => 'Группа A1',
            'course' => ['slug' => $w['course']->slug, 'title' => $w['course']->title['ru']],
            'status' => 'active',
            'starts_at' => '2026-11-05T10:00:00.000Z',
            'ends_at' => '2026-11-12T10:00:00.000Z',
            'stars_total' => 5,
            'target_stars' => 18,
            'runs_total' => 2,
            'boss' => ['unlocked' => false, 'defeated' => false, 'my_attempt' => null],
        ])
        ->and($response->json('caravans.1.status'))->toBe('finished');

    expect(classApiGet($mate)->assertOk()->json('caravans.*.id'))->toBe([$new->id, $old->id]);
});

it('shows a learner outside the class (or one who left it) nothing', function (): void {
    $w = ClassKit::world(2);
    classApiCaravan($w);
    $stranger = Learner::factory()->create();
    $left = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $w['group']->id, 'learner_id' => $left->id, 'status' => 'left']);

    expect(classApiGet($stranger)->assertOk()->json('caravans'))->toBe([])
        ->and(classApiGet($left)->assertOk()->json('caravans'))->toBe([]);
});

it('hides the caravan of a group that is not active', function (): void {
    $w = ClassKit::world(1);
    classApiCaravan($w);
    $w['group']->forceFill(['status' => 'pending'])->save();

    expect(classApiGet($w['learners'][0])->assertOk()->json('caravans'))->toBe([]);
});

it('answers 404 class_caravan_not_found to the caravan of another class, a canceled one and a missing one', function (): void {
    $mine = ClassKit::world(1);
    $other = ClassKit::world(1, chatId: -2002, teacherTelegramId: null);
    $canceled = classApiCaravan($mine, ['status' => ClassCaravanStatus::Canceled, 'active_group_id' => null]);
    $foreign = classApiCaravan($other);
    $me = $mine['learners'][0];

    foreach ([$foreign->id, $canceled->id, 999999] as $id) {
        classApiGet($me, "/{$id}")->assertNotFound()->assertJsonPath('error.code', 'class_caravan_not_found');
    }
});

it('is closed with 403 game_unavailable while the game is off, and for a learner outside the pilot', function (): void {
    $w = ClassKit::world(1);
    $caravan = classApiCaravan($w);
    $me = $w['learners'][0];

    config(['game.access' => 'off']);
    classApiGet($me)->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
    classApiGet($me, "/{$caravan->id}")->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');

    config(['game.access' => 'pilot']);
    classApiGet($me)->assertForbidden()->assertJsonPath('error.code', 'game_unavailable');
});

it('shows one caravan with the table of the class by first names, my own row and no ids of the classmates', function (): void {
    $w = ClassKit::world(3);
    [$me, $second, $third] = $w['learners'];
    $second->forceFill(['username' => 'secret_nick'])->save();
    $caravan = classApiCaravan($w, ['stars_total' => 9, 'runs_total' => 4]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $me->id, 'stars' => 3, 'runs' => 1, 'best_score' => 70]);
    ClassCaravanMember::factory()->create(['class_caravan_id' => $caravan->id, 'learner_id' => $second->id, 'stars' => 6, 'runs' => 3, 'best_score' => 95, 'boss_won_at' => now()]);

    $json = classApiGet($me, "/{$caravan->id}")->assertOk()->json();

    expect($json['members'])->toBe([
        ['name' => 'Ученик 1', 'stars' => 6, 'runs' => 3, 'best_score' => 95, 'boss_won' => true, 'is_me' => false],
        ['name' => 'Ученик 0', 'stars' => 3, 'runs' => 1, 'best_score' => 70, 'boss_won' => false, 'is_me' => true],
    ])
        ->and($json['my'])->toBe(['stars' => 3, 'runs' => 1, 'best_score' => 70, 'boss_won' => false])
        ->and($json['summary'])->toBeNull()
        ->and($json['stars_total'])->toBe(9)
        ->and(json_encode($json))->not->toContain('secret_nick')->not->toContain('learner_id')->not->toContain((string) $third->telegram_user_id);

    // a member who has not played yet: no row of his own
    expect(classApiGet($third, "/{$caravan->id}")->assertOk()->json('my'))->toBeNull();
});

it('tells the fight of the learner with the boss of the class: not yet, lost, won; and while it is going on', function (): void {
    $w = ClassKit::world(1);
    $me = $w['learners'][0];
    $caravan = classApiCaravan($w, ['boss_unlocked_at' => now(), 'boss_defeated_at' => null]);
    $attempt = fn () => classApiGet($me, "/{$caravan->id}")->assertOk()->json('boss');

    expect($attempt())->toBe(['unlocked' => true, 'defeated' => false, 'my_attempt' => null]);

    $run = GameRun::factory()->classBoss($caravan)->create(['learner_id' => $me->id, 'course_id' => $w['course']->id, 'correct' => 3, 'total' => 12]);
    expect($attempt()['my_attempt'])->toBeNull(); // still going on

    $run->forceFill(['status' => 'finished', 'finished_at' => now(), 'active_learner_id' => null, 'finish_reason' => 'completed'])->save();
    expect($attempt()['my_attempt'])->toBe('lost');

    $run->forceFill(['correct' => 10])->save();
    expect($attempt()['my_attempt'])->toBe('won');

    $caravan->forceFill(['boss_defeated_at' => now()])->save();
    expect($attempt()['defeated'])->toBeTrue()
        ->and(classApiGet($me)->json('caravans.0.boss.my_attempt'))->toBe('won');
});

it('never calls an abandoned or expired boss fight won, whatever its accuracy', function (string $reason): void {
    $w = ClassKit::world(1);
    $me = $w['learners'][0];
    $caravan = classApiCaravan($w, ['boss_unlocked_at' => now(), 'boss_defeated_at' => null]);

    GameRun::factory()->classBoss($caravan)->create([
        'learner_id' => $me->id, 'course_id' => $w['course']->id, 'correct' => 10, 'total' => 12,
        'status' => 'finished', 'finished_at' => now(), 'active_learner_id' => null, 'finish_reason' => $reason,
    ]);

    expect(classApiGet($me, "/{$caravan->id}")->assertOk()->json('boss.my_attempt'))->toBe('lost');
})->with(['abandoned', 'expired']);

it('carries the summary of a finished caravan', function (): void {
    $w = ClassKit::world(1);
    $caravan = classApiCaravan($w, ['status' => ClassCaravanStatus::Finished, 'active_group_id' => null, 'stars_total' => 20, 'finished_at' => '2026-11-05 09:00:00', 'boss_unlocked_at' => now()->subDay()]);

    $json = classApiGet($w['learners'][0], "/{$caravan->id}")->assertOk()->json();

    expect($json['status'])->toBe('finished')
        ->and($json['summary'])->toBe(['target_reached' => true, 'boss_defeated' => false, 'finished_at' => '2026-11-05T09:00:00.000Z']);
});

it('names the course in the language of the learner', function (): void {
    $w = ClassKit::world(1);
    $w['learners'][0]->forceFill(['interface_locale' => 'uz'])->save();
    $w['course']->forceFill(['title' => ['ru' => 'Русский', 'uz' => 'Uzbekcha']])->save();
    classApiCaravan($w);

    expect(classApiGet($w['learners'][0])->json('caravans.0.course.title'))->toBe('Uzbekcha');
});

// ---------------------------------------------------------------------- the card on the map and on /today

function classApiMapWorld(): array
{
    $w = ClassKit::world(2);
    $course = Course::factory()->published()->create(['slug' => 'silk', 'title' => ['ru' => 'Шёлк', 'uz' => 'Ipak']]);
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->for($stage)->create(['number' => 1]);
    PracticeKit::words($lesson, 2);

    return $w;
}

it('puts the card of the caravan on its way on the map and on /today, and null when there is none', function (): void {
    $w = classApiMapWorld();
    $me = $w['learners'][0];
    $map = fn () => test()->withHeaders(PracticeKit::headers($me))->getJson('/api/twa/game/map?course=silk')->assertOk();
    $today = fn () => test()->withHeaders(PracticeKit::headers($me))->getJson('/api/twa/today')->assertOk();

    expect($map()->json('class_caravan'))->toBeNull()
        ->and($today()->json('game.class_caravan'))->toBeNull();

    $caravan = classApiCaravan($w, ['stars_total' => 7]);
    $card = ['id' => $caravan->id, 'stars_total' => 7, 'target_stars' => 18, 'ends_at' => '2026-11-12T10:00:00.000Z'];

    expect($map()->json('class_caravan'))->toBe($card)
        ->and($today()->json('game.class_caravan'))->toBe($card);

    // a caravan that is over is not a card
    $caravan->forceFill(['status' => ClassCaravanStatus::Finished, 'active_group_id' => null, 'ends_at' => now()->subMinute()])->save();
    expect($map()->json('class_caravan'))->toBeNull()
        ->and($today()->json('game.class_caravan'))->toBeNull();
});

it('gives a learner who is in no class, or in a class without a caravan, no card; and the earliest caravan to one in two', function (): void {
    $w = classApiMapWorld();
    $outsider = Learner::factory()->create();
    $map = fn (Learner $l) => test()->withHeaders(PracticeKit::headers($l))->getJson('/api/twa/game/map?course=silk')->assertOk()->json('class_caravan');
    classApiCaravan($w);

    expect($map($outsider))->toBeNull();

    $second = ClassKit::world(0, chatId: -4004, teacherTelegramId: null);
    $both = $w['learners'][0];
    GroupMember::factory()->create(['telegram_group_id' => $second['group']->id, 'learner_id' => $both->id]);
    $later = classApiCaravan($second, ['starts_at' => now()->subHour()]);
    $first = ClassCaravan::query()->where('telegram_group_id', $w['group']->id)->sole();

    // the one that started first: the same the runs of the learner join (ClassCaravanService::activeIdFor)
    expect($map($both)['id'])->toBe($later->id)->not->toBe($first->id);
});

it('keeps the card of the caravan off /today and the map while the game is closed, and reads no class table then', function (): void {
    $w = classApiMapWorld();
    $me = $w['learners'][0];
    classApiCaravan($w);
    config(['game.access' => 'off']);

    $queries = QueryLog::during(fn () => test()->withHeaders(PracticeKit::headers($me))->getJson('/api/twa/today')->assertOk()->assertJsonPath('game.class_caravan', null));

    expect(QueryLog::matching($queries, '/class_caravans/'))->toBe([]);
    test()->withHeaders(PracticeKit::headers($me))->getJson('/api/twa/game/map?course=silk')->assertForbidden();
});

it('shows no card of the caravan on the map and on /today in the pilot mode: the caravan is for the whole group', function (): void {
    $w = classApiMapWorld();
    $me = $w['learners'][0];
    $me->forceFill(['practice_pilot_at' => now()])->save();
    classApiCaravan($w, ['stars_total' => 7]);
    config(['game.access' => 'pilot']);
    $headers = PracticeKit::headers($me);

    $map = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk()->assertJsonPath('class_caravan', null));
    $today = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk()->assertJsonPath('game.class_caravan', null));

    expect(QueryLog::matching($map, '/from ["`]class_caravans["`]/'))->toBe([])
        ->and(QueryLog::matching($today, '/from ["`]class_caravans["`]/'))->toBe([]);
});

it('answers 401 without Telegram initData: the caravans of the class and the share of a city', function (): void {
    test()->getJson('/api/twa/game/class')->assertUnauthorized();
    test()->getJson('/api/twa/game/class/1')->assertUnauthorized();
    test()->postJson('/api/twa/game/cities/1/shared', [])->assertUnauthorized();
});

it('reads the card in one statement on the map and on /today', function (): void {
    $w = classApiMapWorld();
    $me = $w['learners'][0];
    classApiCaravan($w);
    $headers = PracticeKit::headers($me);
    test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk(); // warm

    $map = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/game/map?course=silk')->assertOk());
    $today = QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk());

    expect(QueryLog::matching($map, '/from ["`]class_caravans["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($today, '/from ["`]class_caravans["`]/'))->toHaveCount(1);
});

it('costs /today two statements more while the game is open (the runs of the day, the card of the caravan) and none of the game while it is closed', function (): void {
    $w = classApiMapWorld();
    $me = $w['learners'][0];
    classApiCaravan($w);
    $headers = PracticeKit::headers($me);
    $read = function () use ($headers): array {
        test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk(); // warm: the plan, the caches of the request

        return QueryLog::during(fn () => test()->withHeaders($headers)->getJson('/api/twa/today')->assertOk());
    };

    config(['game.access' => 'off']);
    $closed = $read();
    config(['game.access' => 'all']);
    $open = $read();

    expect(count($open) - count($closed))->toBe(2)
        ->and(QueryLog::matching($closed, '/class_caravans|game_runs/'))->toBe([]);
});
