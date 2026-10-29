<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Battle;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeSession;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §7.1: GET /practice/hub - the four modes of a course / a lesson with their state and the numbers of the handlers,
 * the unfinished session of the learner, the summary of the battles. The access states of the hub are in PracticeAccessTest,
 * its JSON shape in PracticeContractTest, its statements in PracticeQueryBudgetTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function hubGet(Learner $learner, string $query): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?{$query}");
}

function hubLearner(): Learner
{
    return Learner::factory()->create();
}

/** A battle row (a learner is busy with one live match only: the players are fresh each time). @param array<string, mixed> $attributes */
function hubBattle(BattleStatus $status, Learner $challenger, Learner $opponent, array $attributes = []): Battle
{
    $live = $status->isLive();

    return Battle::factory()->withParticipants()->create([
        'challenger_id' => $challenger->id,
        'opponent_id' => $opponent->id,
        'status' => $status,
        'phase' => $status === BattleStatus::Playing ? BattlePhase::Answering : null,
        'current_round' => $status === BattleStatus::Playing ? 1 : 0,
        'room_expires_at' => $status === BattleStatus::Room ? now()->addMinutes(2) : null,
        'accepted_at' => $live ? now() : null,
        'finished_at' => $status->isTerminal() ? now() : null,
        ...$attributes,
    ]);
}

it('reports each mode with the numbers its handler gives and the reason when there is too little', function (): void {
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 1);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 8);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 0);
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();

    $modes = hubGet($learner, "course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json('modes');

    expect($modes)->toBe([
        ['mode' => 'pairs', 'state' => 'available', 'available' => 9, 'required' => 3, 'reason' => null, 'estimate_seconds' => 120],
        ['mode' => 'collocation', 'state' => 'not_enough', 'available' => 1, 'required' => 3, 'reason' => 'no_items', 'estimate_seconds' => 90],
        ['mode' => 'sentence', 'state' => 'available', 'available' => 8, 'required' => 3, 'reason' => null, 'estimate_seconds' => 150],
        ['mode' => 'dictation', 'state' => 'not_enough', 'available' => 0, 'required' => 3, 'reason' => 'no_items', 'estimate_seconds' => 180],
    ]);
});

it('shows a switched-off mode as disabled without numbers, and the others as they are', function (): void {
    foreach (PracticeMode::cases() as $mode) {
        PracticeKit::fake($mode, candidates: 9);
    }
    config(['practice.modes.sentence' => false]);
    [$course] = PracticeKit::course();

    $modes = hubGet(Learner::factory()->create(), "course={$course->slug}")->assertOk()->json('modes');

    expect(array_column($modes, 'state', 'mode'))->toBe(['pairs' => 'available', 'collocation' => 'available', 'sentence' => 'disabled', 'dictation' => 'available'])
        ->and($modes[2])->toBe(['mode' => 'sentence', 'state' => 'disabled', 'available' => 0, 'required' => 3, 'reason' => null, 'estimate_seconds' => 150]);
});

it('titles the course and the lesson in the language of the learner', function (): void {
    [$course, $lesson] = PracticeKit::course();

    hubGet(Learner::factory()->create(['interface_locale' => 'uz']), "course={$course->slug}&lesson_id={$lesson->id}")->assertOk()
        ->assertJsonPath('course', ['slug' => 'english-a1', 'title' => 'Ingliz tili A1'])
        ->assertJsonPath('lesson.id', $lesson->id);
    hubGet(Learner::factory()->create(['interface_locale' => 'ru']), "course={$course->slug}")->assertOk()
        ->assertJsonPath('course.title', 'Английский A1')
        ->assertJsonPath('lesson', null);
});

it('answers 404 / 422 for a course or a lesson that cannot be practiced, and 422 for a request that is not one', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    Course::factory()->create(['slug' => 'draft']);
    $theirs = Course::factory()->deckOf(Learner::factory()->create()->id)->create(['slug' => 'their-deck']);
    $other = Course::factory()->published()->create(['slug' => 'other']);
    $foreign = Lesson::factory()->for(Stage::factory()->create(['course_id' => $other->id]))->create();
    $secret = Lesson::factory()->for(Stage::factory()->create(['course_id' => $theirs->id]))->create();

    hubGet($learner, 'course=draft')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    hubGet($learner, 'course=their-deck')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    hubGet($learner, 'course=nothing-like-it')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    hubGet($learner, "course={$course->slug}&lesson_id=999999")->assertNotFound()->assertJsonPath('error.code', 'lesson_not_found');
    hubGet($learner, "course={$course->slug}&lesson_id={$foreign->id}")->assertStatus(422)->assertJsonPath('error.code', 'lesson_not_in_course');
    hubGet($learner, "course={$course->slug}&lesson_id={$secret->id}")->assertNotFound()->assertJsonPath('error.code', 'lesson_not_found');

    hubGet($learner, '')->assertStatus(422);
    hubGet($learner, "course={$course->slug}&lesson_id=0")->assertStatus(422);
    hubGet($learner, "course={$course->slug}&lesson_id=abc")->assertStatus(422);
    hubGet($learner, 'course='.str_repeat('x', 65))->assertStatus(422);
    test()->flushHeaders()->getJson('/api/twa/practice/hub?course=english-a1')->assertUnauthorized();
});

it('hands a learner the hub of their own deck and no hub of the deck of somebody else', function (): void {
    $learner = Learner::factory()->create();
    Course::factory()->deckOf($learner->id)->create(['slug' => 'my-deck']);

    hubGet($learner, 'course=my-deck')->assertOk()->assertJsonPath('course.slug', 'my-deck');
    hubGet(Learner::factory()->create(), 'course=my-deck')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
});

it('summarises the battles of the learner: the invitations to them, the match they are in, and nothing of anybody else', function (): void {
    [$course] = PracticeKit::course();
    $me = Learner::factory()->create();

    $nothing = ['enabled' => true, 'active_battle_id' => null, 'incoming_count' => 0];
    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe($nothing);

    // Somebody else's matches, and mine that are over, do not show (a learner is busy with ONE live match: fresh players each time).
    hubBattle(BattleStatus::Playing, hubLearner(), hubLearner());
    hubBattle(BattleStatus::Invited, hubLearner(), hubLearner());
    hubBattle(BattleStatus::Finished, hubLearner(), $me);
    hubBattle(BattleStatus::Closed, $me, hubLearner());
    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe($nothing);

    // An invitation to me counts until it expires; the expired one does not.
    hubBattle(BattleStatus::Invited, hubLearner(), $me);
    hubBattle(BattleStatus::Invited, hubLearner(), $me, ['invite_expires_at' => now()->subSecond()]);
    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe(['enabled' => true, 'active_battle_id' => null, 'incoming_count' => 1]);

    // A match I am in - the room, the countdown or the game - is "the active battle", and an invitation next to it stays incoming.
    $room = hubBattle(BattleStatus::Room, $me, hubLearner());
    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe(['enabled' => true, 'active_battle_id' => $room->id, 'incoming_count' => 1]);
});

it('counts as the active battle my own invitation that has not expired, and every live status', function (BattleStatus $status): void {
    [$course] = PracticeKit::course();
    $me = Learner::factory()->create();
    $battle = hubBattle($status, hubLearner(), $me);

    // Seen from the OPPONENT's side only an invitation is "incoming"; everything else is "my match".
    $expected = $status === BattleStatus::Invited
        ? ['enabled' => true, 'active_battle_id' => null, 'incoming_count' => 1]
        : ['enabled' => true, 'active_battle_id' => $battle->id, 'incoming_count' => 0];
    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe($expected);

    $challenger = hubLearner();
    $own = hubBattle($status, $challenger, hubLearner());
    expect(hubGet($challenger, "course={$course->slug}")->json('battle'))->toBe(['enabled' => true, 'active_battle_id' => $own->id, 'incoming_count' => 0]);
})->with([BattleStatus::Invited, BattleStatus::Room, BattleStatus::Countdown, BattleStatus::Playing]);

it('follows the switch of the battle in `enabled`, and keeps showing the match that is on', function (): void {
    [$course] = PracticeKit::course();
    $me = Learner::factory()->create();
    $battle = hubBattle(BattleStatus::Playing, $me, hubLearner());

    config(['practice.battle.enabled' => false]);

    expect(hubGet($me, "course={$course->slug}")->json('battle'))->toBe(['enabled' => false, 'active_battle_id' => $battle->id, 'incoming_count' => 0]);
});

it('shows the unfinished session of the learner, whichever course it is of, and no session of anybody else', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$course] = PracticeKit::course();
    $elsewhere = Course::factory()->published()->create(['slug' => 'elsewhere']);
    $me = Learner::factory()->create();
    $other = Learner::factory()->create();
    PracticeSession::factory()->create(['learner_id' => $other->id, 'course_id' => $course->id]);

    expect(hubGet($me, "course={$course->slug}")->json('active_session'))->toBeNull();

    $mine = PracticeSession::factory()->ofMode(PracticeMode::Sentence)->create([
        'learner_id' => $me->id, 'course_id' => $elsewhere->id, 'answered' => 2, 'total' => 5,
        'snapshot' => (new Snapshot(PracticeMode::Sentence, ['ru'], [], [], 'elsewhere', ['ru' => 'Курс']))->toArray(),
    ]);
    expect(hubGet($me, "course={$course->slug}")->json('active_session'))
        ->toBe(['id' => $mine->id, 'mode' => 'sentence', 'course' => 'elsewhere', 'lesson_id' => null, 'progress' => ['done' => 2, 'total' => 5]]);
});
