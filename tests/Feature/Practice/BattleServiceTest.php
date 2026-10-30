<?php

declare(strict_types=1);

use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleClock;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Jobs\SendBattleDmJob;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §8, §14.1 `BattleServiceTest`: the life of a battle on the REAL modes - the invitation and its refusals, accept /
 * decline / cancel / expiry (lazy and by the sweeper), ready -> countdown -> playing, the answers and the points of every
 * mode, round_not_open / round_closed and the grace, the round resolved by the second answer, the time-outs, reveal -> the
 * next round, the end after five rounds (a win, a draw), surrender, absence (one, both), the voided dictation, the rematch,
 * the presence written at most once per 5 s, state_version, the list and the options.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return list<array<string, mixed>> the props of the events with the name, in order */
function battleEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** Runs a closure that must refuse with a BattleException / PracticeException / FriendshipException; returns [code, details]. @return array{0: string, 1: array<string, mixed>} */
function battleRefusal(Closure $fn): array
{
    try {
        $fn();
    } catch (BattleException|PracticeException $e) {
        return [$e->errorCode, $e->details];
    } catch (FriendshipException $e) {
        return [$e->errorCode, []];
    }

    throw new RuntimeException('Nothing was refused.');
}

function battleTick(string $time): CarbonImmutable
{
    CarbonImmutable::setTestNow($time);

    return CarbonImmutable::now();
}

// ------------------------------------------------------------------------------------------------------------ invite

it('invites a friend: an invited row, the two participants with the challenger busy, a snapshot of five rounds in both languages, the event and the DM job', function (): void {
    Queue::fake();
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Sentence, $course, $lesson);
    $battle = BattleKit::fresh($battle);

    expect($battle->status)->toBe(BattleStatus::Invited)
        ->and($battle->mode)->toBe(PracticeMode::Sentence)
        ->and([$battle->challenger_id, $battle->opponent_id, $battle->friendship_id, $battle->course_id, $battle->lesson_id])->toBe([$me->id, $friend->id, $friendship->id, $course->id, $lesson->id])
        ->and($battle->invite_expires_at->toDateTimeString())->toBe('2026-10-30 10:05:00')
        ->and([$battle->current_round, $battle->phase, $battle->state_version, $battle->rematch_of_id])->toBe([0, null, 0, null])
        ->and($battle->snapshot['locales'])->toBe(['ru', 'uz'])
        ->and($battle->snapshot['elements'])->toHaveCount(5)
        ->and($battle->snapshot['course_title'])->toBe(['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1'])
        ->and($battle->snapshot['lesson_title'])->toBe('Еда');
    foreach ($battle->snapshot['elements'] as $element) {
        expect(array_keys($element['prompt']))->toBe(['ru', 'uz']);
    }

    $challenger = $battle->participant($me->id);
    $opponent = $battle->participant($friend->id);
    expect([$challenger->role->value, $challenger->active_learner_id, $challenger->locale, $challenger->ready_at, $challenger->last_seen_at, $challenger->score])->toBe(['challenger', $me->id, 'ru', null, null, 0])
        ->and([$opponent->role->value, $opponent->active_learner_id, $opponent->locale])->toBe(['opponent', null, 'uz']);

    expect(battleEvents('battle.invited'))->toEqual([['battle_id' => $battle->id, 'mode' => 'sentence', 'course_id' => $course->id, 'lesson_id' => $lesson->id, 'rematch' => false]])
        ->and(ProductEvent::query()->where('name', 'battle.invited')->sole()->learner_id)->toBe($me->id);
    Queue::assertPushed(SendBattleDmJob::class, fn (SendBattleDmJob $job): bool => $job->kind === 'invited' && $job->battleId === $battle->id);
});

it('refuses an invitation for the reasons of docs/16 §8.2, each with its code, and writes nothing', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $stranger = Learner::factory()->create();
    $pending = Friendship::factory()->between($me, Learner::factory()->create())->pending()->create();
    $blockedFriend = Learner::factory()->create(['blocked_at' => now()]);
    $withBlocked = Friendship::factory()->between($me, $blockedFriend)->create();
    $private = Course::factory()->published()->create(['slug' => 'private-deck', 'visibility' => 'private']);
    $empty = PracticeKit::course('empty-course')[0];
    $invite = fn (Learner $by, ?int $friendshipId, PracticeMode $mode, string $slug, ?int $lessonId = null) => BattleKit::service()->invite($by, $friendshipId, $mode, $slug, $lessonId, null);

    expect(battleRefusal(fn () => $invite($stranger, $friendship->id, PracticeMode::Pairs, $course->slug)))->toBe(['friendship_not_found', []])
        ->and(battleRefusal(fn () => $invite($me, $pending->id, PracticeMode::Pairs, $course->slug)))->toBe(['not_friends', []])
        ->and(battleRefusal(fn () => $invite($me, $withBlocked->id, PracticeMode::Pairs, $course->slug)))->toBe(['friendship_not_found', []])
        ->and(battleRefusal(fn () => $invite($me, null, PracticeMode::Pairs, $course->slug)))->toBe(['friendship_not_found', []])
        ->and(battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Pairs, $private->slug)))->toBe(['course_not_found', []])
        ->and(battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Pairs, 'no-such-course')))->toBe(['course_not_found', []])
        ->and(battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Pairs, $course->slug, 999_999)))->toBe(['lesson_not_found', []]);

    // Too little material: 422 with the numbers of both players' languages and the event with `battle = true`.
    [$code, $details] = battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Pairs, $empty->slug));
    expect($code)->toBe('battle_material_unavailable')
        ->and($details)->toBe(['mode' => 'pairs', 'available' => 0, 'required' => 20, 'reason' => 'no_words', 'course' => 'empty-course', 'lesson_id' => null])
        ->and(battleEvents('practice.not_enough_material'))->toEqual([['mode' => 'pairs', 'course_id' => $empty->id, 'lesson_id' => null, 'available' => 0, 'required' => 20, 'reason' => 'no_words', 'battle' => true]]);

    // The switches: the battle, the mode; the friend without access to the practice.
    config(['practice.battle.enabled' => false]);
    expect(battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Pairs, $course->slug)))->toBe(['battle_disabled', []]);
    config(['practice.battle.enabled' => true, 'practice.modes.sentence' => false]);
    expect(battleRefusal(fn () => $invite($me, $friendship->id, PracticeMode::Sentence, $course->slug)))->toBe(['practice_mode_disabled', ['mode' => 'sentence']]);
    config(['practice.modes.sentence' => true, 'practice.access' => 'pilot']);
    $me->update(['practice_pilot_at' => now()]);
    expect(battleRefusal(fn () => $invite($me->fresh() ?? $me, $friendship->id, PracticeMode::Pairs, $course->slug)))->toBe(['battle_friend_no_access', []]);
    $me->update(['practice_pilot_at' => null]);
    expect(battleRefusal(fn () => $invite($me->fresh() ?? $me, $friendship->id, PracticeMode::Pairs, $course->slug)))->toBe(['practice_unavailable', []]);

    expect(Battle::query()->count())->toBe(0)
        ->and(BattleParticipant::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'like', 'battle.%')->count())->toBe(0);
});

it('lets a learner have one open battle: a second invitation is 409 battle_busy with the id of the open one; the invited side is not busy until they accept', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create();
    $other = Friendship::factory()->between($me, $third)->create();
    $friendsThird = Friendship::factory()->between($friend, $third)->create();

    $first = BattleKit::invite($me, $friendship, PracticeMode::Collocation, $course);

    expect(battleRefusal(fn () => BattleKit::invite($me, $other, PracticeMode::Pairs, $course)))->toBe(['battle_busy', ['battle_id' => $first->id]])
        ->and(Battle::query()->count())->toBe(1);

    // The invited friend is free: an invitation of their own to somebody else goes through.
    $byFriend = BattleKit::invite($friend, $friendsThird, PracticeMode::Pairs, $course);
    expect($byFriend->status)->toBe(BattleStatus::Invited)
        ->and(BattleParticipant::query()->whereNotNull('active_learner_id')->pluck('active_learner_id')->sort()->values()->all())->toBe(collect([$me->id, $friend->id])->sort()->values()->all());

    // Once the first is over, the challenger may invite again.
    BattleKit::service()->cancel($me, $first->id);
    expect(BattleKit::invite($me, $other, PracticeMode::Pairs, $course)->status)->toBe(BattleStatus::Invited);
});

// ------------------------------------------------------------------------------------------------------------ accept / decline / cancel / expiry

it('accepts an invitation: a room with both present, a 120 s life, version +1, the event; accepting again changes nothing; the challenger and a stranger cannot accept', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson);
    $stranger = Learner::factory()->create();

    expect(battleRefusal(fn () => BattleKit::service()->accept($stranger, $battle->id)))->toBe(['battle_not_found', []])
        ->and(battleRefusal(fn () => BattleKit::service()->accept($me, $battle->id)))->toBe(['battle_not_recipient', []]);

    battleTick('2026-10-30 10:01:00');
    $room = BattleKit::service()->accept($friend, $battle->id);

    expect($room->status)->toBe(BattleStatus::Room)
        ->and($room->accepted_at?->toDateTimeString())->toBe('2026-10-30 10:01:00')
        ->and($room->room_expires_at?->toDateTimeString())->toBe('2026-10-30 10:03:00')
        ->and($room->state_version)->toBe(1);
    $fresh = BattleKit::fresh($battle);
    foreach ([$me, $friend] as $player) {
        $participant = $fresh->participant($player->id);
        expect($participant->active_learner_id)->toBe($player->id)
            ->and($participant->last_seen_at?->toDateTimeString())->toBe('2026-10-30 10:01:00');
    }
    expect(battleEvents('battle.accepted'))->toEqual([['battle_id' => $battle->id]])
        ->and(ProductEvent::query()->where('name', 'battle.accepted')->sole()->learner_id)->toBe($me->id); // the actor of the events is the challenger

    // Idempotent: a second tap, by either player, is the same room.
    expect(BattleKit::service()->accept($friend, $battle->id)->state_version)->toBe(1)
        ->and(BattleKit::service()->accept($me, $battle->id)->status)->toBe(BattleStatus::Room)
        ->and(battleEvents('battle.accepted'))->toHaveCount(1);
});

it('refuses the acceptance of a learner busy with another battle: 409 battle_busy with the id of theirs, and the invitation stays open', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create();
    $friendsThird = Friendship::factory()->between($friend, $third)->create();

    $toFriend = BattleKit::invite($me, $friendship, PracticeMode::Collocation, $course);
    $byFriend = BattleKit::invite($friend, $friendsThird, PracticeMode::Pairs, $course); // the friend is busy with an invitation of their own

    expect(battleRefusal(fn () => BattleKit::service()->accept($friend, $toFriend->id)))->toBe(['battle_busy', ['battle_id' => $byFriend->id]])
        ->and(BattleKit::fresh($toFriend)->status)->toBe(BattleStatus::Invited)
        ->and(BattleKit::fresh($toFriend)->participant($friend->id)->active_learner_id)->toBeNull()
        ->and(battleEvents('battle.accepted'))->toEqual([]);

    // The friend withdraws theirs and can accept.
    BattleKit::service()->cancel($friend, $byFriend->id);
    expect(BattleKit::service()->accept($friend, $toFriend->id)->status)->toBe(BattleStatus::Room);
});

it('declines, cancels and expires an invitation: closed with the reason, both players released, one battle.closed each; the next action is 409 battle_closed with the reason', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create();
    $otherPair = Friendship::factory()->between($friend, $third)->create();

    // Declined by the friend (not by the challenger).
    $declined = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    expect(battleRefusal(fn () => BattleKit::service()->decline($me, $declined->id)))->toBe(['battle_not_recipient', []]);
    BattleKit::service()->decline($friend, $declined->id);
    $row = BattleKit::fresh($declined);
    expect([$row->status, $row->end_reason, $row->finished_at?->toDateTimeString(), $row->phase])->toBe([BattleStatus::Closed, BattleEndReason::Declined, BattleKit::T0, null])
        ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(battleRefusal(fn () => BattleKit::service()->accept($friend, $declined->id)))->toBe(['battle_closed', ['end_reason' => 'declined']])
        ->and(battleRefusal(fn () => BattleKit::service()->decline($friend, $declined->id)))->toBe(['battle_closed', ['end_reason' => 'declined']]);

    // Canceled by the challenger (not by the friend).
    $canceled = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    expect(battleRefusal(fn () => BattleKit::service()->cancel($friend, $canceled->id)))->toBe(['battle_not_challenger', []]);
    BattleKit::service()->cancel($me, $canceled->id);
    expect(BattleKit::fresh($canceled)->end_reason)->toBe(BattleEndReason::Canceled)
        ->and(battleRefusal(fn () => BattleKit::service()->ready($me, $canceled->id)))->toBe(['battle_closed', ['end_reason' => 'canceled']]);

    // Expired: lazily by the acceptance that comes too late ...
    $late = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    battleTick('2026-10-30 10:05:00');
    expect(battleRefusal(fn () => BattleKit::service()->accept($friend, $late->id)))->toBe(['battle_closed', ['end_reason' => 'invite_expired']])
        ->and(BattleKit::fresh($late)->status)->toBe(BattleStatus::Closed)
        ->and(BattleKit::fresh($late)->participant($me->id)->active_learner_id)->toBeNull();

    // ... by a view of the list of the other side ...
    $seen = BattleKit::invite($friend, $otherPair, PracticeMode::Pairs, $course);
    battleTick('2026-10-30 10:10:01');
    expect(BattleKit::reader()->view(BattleKit::reader()->find($third, $seen->id), $third, CarbonImmutable::now())->status)->toBe('closed')
        ->and(BattleKit::fresh($seen)->end_reason)->toBe(BattleEndReason::InviteExpired);

    // ... and by the sweeper for the rest, exactly once each.
    $swept = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    battleTick('2026-10-30 10:15:01');
    expect(app(BattleJanitor::class)->sweep())->toBe(1)
        ->and(app(BattleJanitor::class)->sweep())->toBe(0)
        ->and(BattleKit::fresh($swept)->end_reason)->toBe(BattleEndReason::InviteExpired)
        ->and(BattleKit::fresh($swept)->state_version)->toBe(1);

    $closed = battleEvents('battle.closed');
    expect(array_column($closed, 'end_reason'))->toBe(['declined', 'canceled', 'invite_expired', 'invite_expired', 'invite_expired'])
        ->and(array_column($closed, 'battle_id'))->toBe([$declined->id, $canceled->id, $late->id, $seen->id, $swept->id]);
});

it('closes an invitation whose friendship ended (canceled) and keeps one waiting while the battle is switched off', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course);
    config(['practice.battle.enabled' => false]);
    expect(battleRefusal(fn () => BattleKit::service()->accept($friend, $battle->id)))->toBe(['battle_disabled', []])
        ->and(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Invited);
    config(['practice.battle.enabled' => true]);

    $friendship->delete();
    expect(battleRefusal(fn () => BattleKit::service()->accept($friend, $battle->id)))->toBe(['battle_closed', ['end_reason' => 'canceled']])
        ->and(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Closed);
});

it('cancels a room from either side with the role that left, and refuses to cancel a game (surrender instead)', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Collocation, $course);
    BattleKit::service()->cancel($friend, $room->id);
    $row = BattleKit::fresh($room);
    expect([$row->status, $row->end_reason, $row->meta['left']])->toBe([BattleStatus::Closed, BattleEndReason::Canceled, 'opponent'])
        ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null]);

    $playing = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    expect(battleRefusal(fn () => BattleKit::service()->cancel($me, $playing->id)))->toBe(['battle_wrong_state', ['status' => 'playing']]);
});

it('expires a room nobody made ready after 120 s (room_expired), and cancels one a player left for more than 30 s', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Pairs, $course);
    // Both keep polling, so nobody is absent; the room just runs out.
    foreach (['10:00:25', '10:00:50', '10:01:15', '10:01:40', '10:01:59'] as $time) {
        BattleKit::bothPoll($room, $me, $friend, "2026-10-30 {$time}");
    }
    expect(BattleKit::fresh($room)->status)->toBe(BattleStatus::Room);
    battleTick('2026-10-30 10:02:00');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $room->id), $me, CarbonImmutable::now());
    expect([$poll->status, $poll->endReason])->toBe(['closed', 'room_expired']);

    // The challenger polls, the friend went away right after accepting: 31 s later the room is canceled, `meta.left = opponent`.
    $left = BattleKit::room($me, $friend, $friendship, PracticeMode::Pairs, $course);
    battleTick('2026-10-30 10:02:30');
    BattleKit::reader()->poll(BattleKit::reader()->find($me, $left->id), $me, CarbonImmutable::now());
    expect(BattleKit::fresh($left)->status)->toBe(BattleStatus::Room);
    battleTick('2026-10-30 10:02:31');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $left->id), $me, CarbonImmutable::now());
    expect([$poll->status, $poll->endReason, BattleKit::fresh($left)->meta['left']])->toBe(['closed', 'canceled', 'opponent']);
});

// ------------------------------------------------------------------------------------------------------------ ready -> countdown -> playing

it('starts the match: one ready keeps the room, both make a countdown with round 1 and its deadline, the first poll at starts_at opens round 1', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    expect(battleRefusal(fn () => BattleKit::service()->ready(Learner::factory()->create(), $room->id)))->toBe(['battle_not_found', []]);

    battleTick('2026-10-30 10:00:10');
    $one = BattleKit::service()->ready($me, $room->id);
    expect([$one->status, $one->state_version])->toBe([BattleStatus::Room, 2])
        ->and(BattleKit::fresh($room)->participant($me->id)->ready_at?->toDateTimeString())->toBe('2026-10-30 10:00:10')
        ->and(BattleKit::service()->ready($me, $room->id)->state_version)->toBe(2); // a second tap

    battleTick('2026-10-30 10:00:12');
    $both = BattleKit::service()->ready($friend, $room->id);
    expect([$both->status, $both->current_round, $both->phase, $both->state_version])->toBe([BattleStatus::Countdown, 1, null, 3])
        ->and($both->starts_at?->toDateTimeString())->toBe('2026-10-30 10:00:15')
        ->and($both->round_deadline_at?->toDateTimeString())->toBe('2026-10-30 10:00:45') // starts_at + 30 s of a collocation
        ->and(battleEvents('battle.started'))->toEqual([['battle_id' => $room->id, 'mode' => 'collocation']]);

    // The view during the countdown: round 1 without a phase, with the deadline; the answer is `round_not_open`.
    $view = BattleKit::reader()->view(BattleKit::reader()->find($friend, $room->id), $friend, CarbonImmutable::now());
    expect($view->round?->toArray())->toBe(['no' => 1, 'phase' => null, 'deadline_at' => '2026-10-30T10:00:45.000Z', 'phase_ends_at' => null, 'my_answered' => false, 'opponent_answered' => false])
        ->and($view->ready)->toBe(['me' => true, 'opponent' => true])
        ->and(battleRefusal(fn () => BattleKit::answer($room, $me, 1, BattleKit::correct(BattleKit::element($room, 1)))))->toBe(['round_not_open', ['round' => 1, 'phase' => null]]);
    battleTick('2026-10-30 10:00:14');
    expect(BattleKit::reader()->poll(BattleKit::reader()->find($me, $room->id), $me, CarbonImmutable::now())->status)->toBe('countdown');

    // At starts_at whoever polls first opens round 1; the deadline does not move; ready is now a no-op.
    battleTick('2026-10-30 10:00:15');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($friend, $room->id), $friend, CarbonImmutable::now());
    expect([$poll->status, $poll->version, $poll->round?->no, $poll->round?->phase])->toBe(['playing', 4, 1, 'answering'])
        ->and($poll->round?->deadlineAt?->toDateTimeString())->toBe('2026-10-30 10:00:45')
        ->and(BattleKit::service()->ready($me, $room->id)->state_version)->toBe(4)
        ->and(battleRefusal(fn () => BattleKit::service()->ready($me, BattleKit::invite($me, Friendship::factory()->between($me, Learner::factory()->create())->create(), PracticeMode::Pairs, $course)->id)))->toBe(['battle_busy', ['battle_id' => $room->id]]);
});

it('checks the five clips of a dictation at ready and lists them for preloading; a missing clip voids the match', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Dictation, $course);
    $view = BattleKit::reader()->view(BattleKit::reader()->find($me, $room->id), $me, CarbonImmutable::now());
    expect($view->audioPreload)->toHaveCount(5);
    foreach ($view->audioPreload ?? [] as $i => $url) {
        expect($url)->toBe("/api/twa/practice/battles/{$room->id}/audio/".$room->snapshot['elements'][$i]['id']);
    }

    BattleKit::service()->cancel($me, $room->id);
    $countdown = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Dictation, $course);
    expect($countdown->status)->toBe(BattleStatus::Countdown)
        ->and($countdown->round_deadline_at?->toDateTimeString())->toBe('2026-10-30 10:01:03') // 60 s of a dictation after the 3 s countdown
        ->and(BattleKit::reader()->view($countdown, $me, CarbonImmutable::now())->audioPreload)->toHaveCount(5);
    battleTick($countdown->starts_at?->toDateTimeString() ?? BattleKit::T0);
    expect(BattleKit::reader()->view($countdown, $me, CarbonImmutable::now())->audioPreload)->toBeNull();
    BattleKit::service()->surrender($me, $countdown->id);

    // A clip that is not on the disk any more: the room is voided at `ready`, both are released, the event says the server looked.
    $voided = BattleKit::room($me, $friend, $friendship, PracticeMode::Dictation, $course);
    Storage::disk('practice_audio')->delete($voided->snapshot['elements'][2]['audio_path']);
    $after = BattleKit::service()->ready($friend, $voided->id);
    expect([$after->status, $after->end_reason, $after->meta['voided_reason'], $after->meta['rounds_played']])->toBe([BattleStatus::Closed, BattleEndReason::Voided, 'audio_missing', 0])
        ->and(BattleKit::fresh($voided)->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(battleEvents('practice.audio_failed'))->toEqual([['battle_id' => $voided->id, 'source' => 'phrase', 'reason' => 'file_missing', 'verified' => true]])
        ->and(collect(battleEvents('battle.closed'))->last()['end_reason'] ?? null)->toEqual('voided');
});

// ------------------------------------------------------------------------------------------------------------ answers

it('grades and scores a round of every mode for both players, and shows one review to each in their own language', function (PracticeMode $mode, int $pointsRight, int $pointsWrong, string $wrongVerdict, string $correctKey): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, $mode, $course, $lesson);
    $element = BattleKit::element($battle, 1);

    $mine = BattleKit::answer($battle, $me, 1, BattleKit::correct($element));
    expect([$mine->verdict, $mine->points, $mine->roundResolved])->toBe([Verdict::Correct, $pointsRight, false])
        ->and($mine->poll->round?->toArray())->toMatchArray(['no' => 1, 'phase' => 'answering', 'my_answered' => true, 'opponent_answered' => false])
        ->and($mine->poll->scores)->toBe(['me' => 0, 'opponent' => 0]); // nothing is told before the round is resolved

    // Before the resolution the friend's round has no review and sees "the opponent answered".
    $pending = BattleKit::reader()->round(BattleKit::reader()->find($friend, $battle->id), $friend, 1, CarbonImmutable::now());
    expect($pending->reveal)->toBeNull()
        ->and($pending->phase)->toBe('answering')
        ->and(BattleKit::reader()->poll(BattleKit::reader()->find($friend, $battle->id), $friend, CarbonImmutable::now())->round?->toArray())->toMatchArray(['my_answered' => false, 'opponent_answered' => true]);

    $theirs = BattleKit::answer($battle, $friend, 1, BattleKit::wrong($element));
    expect([$theirs->verdict->value, $theirs->points, $theirs->roundResolved])->toBe([$wrongVerdict, $pointsWrong, true])
        ->and($theirs->poll->round?->toArray())->toMatchArray(['no' => 1, 'phase' => 'reveal', 'phase_ends_at' => '2026-10-30T10:00:07.000Z', 'my_answered' => true, 'opponent_answered' => true])
        ->and($theirs->poll->scores)->toBe(['me' => $pointsWrong, 'opponent' => $pointsRight]);

    $row = BattleKit::fresh($battle);
    expect([$row->phase, $row->state_version])->toBe([BattlePhase::Reveal, 6]) // accept 1, ready 2, countdown 3, playing 4, two answers 6
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([$pointsRight, $pointsWrong])
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(2);

    // The same round for each player: the right answer in the form of the mode, both answers, the texts in the viewer's language.
    $ru = BattleKit::reader()->round(BattleKit::reader()->find($me, $battle->id), $me, 1, CarbonImmutable::now());
    $uz = BattleKit::reader()->round(BattleKit::reader()->find($friend, $battle->id), $friend, 1, CarbonImmutable::now());
    expect($ru->phase)->toBe('reveal')
        ->and($ru->reveal)->not->toBeNull()
        ->and(array_keys($ru->reveal ?? []))->toBe(['correct', 'me', 'opponent', 'explanation', 'translation'])
        ->and($ru->reveal['correct'] ?? [])->toHaveKey($correctKey)
        ->and(array_keys($ru->reveal['correct'] ?? []))->not->toContain('translation', 'explanation')
        ->and($ru->reveal['me'] ?? null)->toBe(['answer' => $mine->poll->id === $battle->id ? BattleAnswer::query()->where('battle_id', $battle->id)->where('learner_id', $me->id)->sole()->payload : null, 'verdict' => 'correct', 'points' => $pointsRight])
        ->and($ru->reveal['opponent']['verdict'] ?? null)->toBe($wrongVerdict)
        ->and($ru->reveal['opponent']['points'] ?? null)->toBe($pointsWrong)
        ->and($uz->reveal['correct'] ?? null)->toBe($ru->reveal['correct'] ?? null)
        ->and($uz->reveal['me'] ?? null)->toBe($ru->reveal['opponent'] ?? null)
        ->and($uz->reveal['opponent'] ?? null)->toBe($ru->reveal['me'] ?? null);
    if ($mode !== PracticeMode::Pairs) {
        expect($ru->reveal['translation'] ?? null)->not->toBe($uz->reveal['translation'] ?? null)
            ->and((string) ($ru->reveal['translation'] ?? ''))->toMatch('/^(Перевод|Предложение|Фраза) \d+\.?$/u')
            ->and((string) ($uz->reveal['translation'] ?? ''))->toMatch('/^(Tarjima|Gap|Ibora) \d+\.?$/u');
    }
    // The element itself never carries the secrets, in either language.
    foreach ([$ru->element, $uz->element] as $shown) {
        expect(array_intersect(array_keys($shown), ['pairs', 'correct_ids', 'accepted', 'accepted_display', 'expected', 'full_text', 'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id']))->toBe([]);
    }
})->with([
    'pairs' => [PracticeMode::Pairs, 100, 50, 'wrong', 'pairs'],
    'collocation' => [PracticeMode::Collocation, 100, 0, 'wrong', 'correct_option_ids'],
    'sentence' => [PracticeMode::Sentence, 100, 0, 'wrong', 'correct_text'],
    'dictation' => [PracticeMode::Dictation, 100, 0, 'wrong', 'expected'],
]);

it('refuses a round that is not the current one, a round past its deadline and grace, and takes an answer 1 s late within the grace', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $deadline = BattleKit::fresh($battle)->round_deadline_at?->toDateTimeString() ?? '';
    expect($deadline)->toBe('2026-10-30 10:00:33');

    expect(battleRefusal(fn () => BattleKit::answer($battle, $me, 2, BattleKit::correct(BattleKit::element($battle, 2)))))->toBe(['round_not_open', ['round' => 2, 'phase' => 'answering']])
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $me, 0, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['round_closed', ['round' => 0, 'phase' => 'answering']]);

    // Both are here (they poll); 1.0 s after the deadline my answer is within the 1.5 s grace: taken.
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:20');
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:33');
    battleTick('2026-10-30 10:00:34');
    $late = BattleKit::answer($battle, $me, 1, BattleKit::correct(BattleKit::element($battle, 1)));
    expect([$late->verdict, $late->points, $late->acceptedAt->toDateTimeString()])->toBe([Verdict::Correct, 100, '2026-10-30 10:00:34']);

    // 1.5 s after: the grace is over - the friend's answer is refused, and the round is resolved by the clock with a time-out for them.
    battleTick('2026-10-30 10:00:34.5');
    expect(battleRefusal(fn () => BattleKit::answer($battle, $friend, 1, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['round_closed', ['round' => 1, 'phase' => 'reveal']]);
    $row = BattleKit::fresh($battle);
    $timeout = BattleAnswer::query()->where('battle_id', $battle->id)->where('learner_id', $friend->id)->sole();
    expect($row->phase)->toBe(BattlePhase::Reveal)
        ->and([$timeout->verdict, $timeout->points, $timeout->payload, $timeout->round])->toBe([Verdict::Timeout, 0, ['timeout' => true], 1])
        ->and($row->participant($me->id)->score)->toBe(100)
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $friend, 1, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['round_closed', ['round' => 1, 'phase' => 'reveal']]);

    // A round that is over stays closed once the next one is on.
    battleTick('2026-10-30 10:00:39');
    BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    expect(BattleKit::fresh($battle)->current_round)->toBe(2)
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $friend, 1, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['round_closed', ['round' => 1, 'phase' => 'answering']]);
});

it('takes one final answer per player and round: a second one is 409 with the first verdict and points, a repeated request returns the same without a second row', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Sentence, $course);
    $element = BattleKit::element($battle, 1);
    $requestId = (string) Str::uuid();

    $first = BattleKit::answer($battle, $me, 1, BattleKit::wrong($element), $requestId);
    expect([$first->verdict, $first->points])->toBe([Verdict::Wrong, 0]);

    $again = BattleKit::answer($battle, $me, 1, BattleKit::correct($element), strtoupper($requestId)); // the same request, another body, another case
    expect([$again->verdict, $again->points, $again->roundResolved, $again->acceptedAt->toDateTimeString()])->toBe([Verdict::Wrong, 0, false, '2026-10-30 10:00:03'])
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $me, 1, BattleKit::correct($element))))->toBe(['answer_already_accepted', ['verdict' => 'wrong', 'points' => 0]])
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(1)
        ->and(BattleKit::fresh($battle)->state_version)->toBe(5);

    // After the round is resolved the repeat still answers 200 and says the round is resolved.
    BattleKit::answer($battle, $friend, 1, BattleKit::correct($element));
    $repeat = BattleKit::answer($battle, $me, 1, BattleKit::wrong($element), $requestId);
    expect([$repeat->verdict, $repeat->roundResolved, $repeat->poll->scores])->toBe([Verdict::Wrong, true, ['me' => 0, 'opponent' => 100]]);
});

it('refuses a malformed answer with the 422 of the mode and writes nothing, yet keeps the transition the same request made', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
    battleTick($battle->starts_at?->toDateTimeString() ?? BattleKit::T0);

    // The request that turns the countdown into round 1 is refused for its shape: the round is open all the same.
    expect(battleRefusal(fn () => BattleKit::answer($battle, $me, 1, ['nonsense' => true])))->toBe(['invalid_answer_shape', ['expected' => 'option_id']])
        ->and(BattleKit::fresh($battle)->status)->toBe(BattleStatus::Playing)
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $me, 1, ['option_id' => 'oNOPE000000'])))->toBe(['unknown_element_id', []])
        ->and(BattleAnswer::query()->count())->toBe(0)
        ->and(BattleKit::fresh($battle)->meta)->toBe([]);

    // Pairs: a table that is not a bijection (a translation twice) is 422 `bijection`; an id of another round is `unknown_element_id`.
    [$me2, $friend2, $course2, $lesson2, $friendship2] = BattleKit::world(slug: 'english-a1-pairs');
    $pairs = BattleKit::playing($me2, $friend2, $friendship2, PracticeMode::Pairs, $course2);
    $table = BattleKit::element($pairs, 1)['pairs'];
    $lefts = array_keys($table);
    $twice = $table;
    $twice[$lefts[1]] = $table[$lefts[0]];
    $foreign = $table;
    $foreign[$lefts[0]] = array_values(BattleKit::element($pairs, 2)['pairs'])[0];
    expect(battleRefusal(fn () => BattleKit::answer($pairs, $me2, 1, ['pairs' => $twice])))->toBe(['invalid_answer_shape', ['expected' => 'bijection']])
        ->and(battleRefusal(fn () => BattleKit::answer($pairs, $me2, 1, ['pairs' => $foreign])))->toBe(['unknown_element_id', []])
        ->and(battleRefusal(fn () => BattleKit::answer($pairs, $me2, 1, ['left_id' => $lefts[0], 'right_id' => $table[$lefts[0]]])))->toBe(['invalid_answer_shape', ['expected' => 'bijection']])
        ->and(BattleAnswer::query()->where('battle_id', $pairs->id)->count())->toBe(0);
});

it('writes a time-out for each missing answer when the deadline and the grace pass, and resolves the round with 0 points for it', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Pairs, $course);
    expect(BattleKit::fresh($battle)->round_deadline_at?->toDateTimeString())->toBe('2026-10-30 10:00:48'); // 45 s of pairs

    // Both poll (they are here), nobody answers round 1: at deadline + 1.5 s the first poll writes two time-outs and begins the reveal.
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:25');
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:00:49.4');
    expect(BattleKit::fresh($battle)->phase)->toBe(BattlePhase::Answering);
    battleTick('2026-10-30 10:00:49.5');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    $timeouts = BattleAnswer::query()->where('battle_id', $battle->id)->where('round', 1)->get();
    expect($poll->round?->phase)->toBe('reveal')
        ->and($poll->scores)->toBe(['me' => 0, 'opponent' => 0])
        ->and($timeouts)->toHaveCount(2)
        ->and($timeouts->pluck('verdict')->unique()->all())->toBe([Verdict::Timeout])
        ->and($timeouts->pluck('request_id')->unique())->toHaveCount(2)
        ->and($timeouts->first()?->request_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/'); // uuid v5, deterministic

    // The review of a timed-out round: no answers, both `timeout`, the right table is there.
    $round = BattleKit::reader()->round(BattleKit::reader()->find($friend, $battle->id), $friend, 1, CarbonImmutable::now());
    expect($round->reveal['me'] ?? null)->toBe(['answer' => null, 'verdict' => 'timeout', 'points' => 0])
        ->and($round->reveal['opponent'] ?? null)->toBe(['answer' => null, 'verdict' => 'timeout', 'points' => 0])
        ->and($round->reveal['correct']['pairs'] ?? null)->toBe(BattleKit::element($battle, 1)['pairs']);

    // Round 2: one answers, the other does not - one time-out, the points of the one.
    battleTick('2026-10-30 10:00:53.5');
    BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    $row = BattleKit::fresh($battle);
    expect([$row->current_round, $row->phase])->toBe([2, BattlePhase::Answering])
        ->and($row->round_deadline_at?->toDateTimeString())->toBe('2026-10-30 10:01:38'); // now + 45 s
    BattleKit::answer($battle, $friend, 2, BattleKit::correct(BattleKit::element($battle, 2)));
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:01:15');
    BattleKit::bothPoll($battle, $me, $friend, '2026-10-30 10:01:39.5');
    $row = BattleKit::fresh($battle);
    expect(BattleAnswer::query()->where('battle_id', $battle->id)->where('round', 2)->where('verdict', 'timeout')->sole()->learner_id)->toBe($me->id)
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([0, 100]);
});

it('plays five rounds to the end: reveal -> next round by the clock, the winner by score, the result for each side, the event once', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    $version = 4;

    for ($round = 1; $round <= 5; $round++) {
        $element = BattleKit::element($battle, $round);
        // Rounds 1-3: I am right, the friend wrong; round 4: both right; round 5: I skip, the friend is right. 400 : 200.
        $mine = $round === 5 ? ['skip' => true] : BattleKit::correct($element);
        $theirs = $round <= 3 ? BattleKit::wrong($element) : BattleKit::correct($element);

        BattleKit::answer($battle, $me, $round, $mine);
        $outcome = BattleKit::answer($battle, $friend, $round, $theirs);
        $version += 2;
        expect($outcome->roundResolved)->toBeTrue()
            ->and($outcome->poll->version)->toBe($version)
            ->and($outcome->poll->round?->phaseEndsAt?->toDateTimeString())->toBe(CarbonImmutable::now()->addSeconds(4)->toDateTimeString());

        // A poll before the end of the reveal changes nothing; the one at its end opens the next round (or ends the match).
        battleTick(CarbonImmutable::now()->addSeconds(3)->toDateTimeString());
        expect(BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now())->version)->toBe($version);
        battleTick(CarbonImmutable::now()->addSecond()->toDateTimeString());
        $poll = BattleKit::reader()->poll(BattleKit::reader()->find($friend, $battle->id), $friend, CarbonImmutable::now());
        $version++;
        expect($poll->version)->toBe($version);
        if ($round < 5) {
            expect([$poll->status, $poll->round?->no, $poll->round?->phase])->toBe(['playing', $round + 1, 'answering'])
                ->and($poll->round?->deadlineAt?->toDateTimeString())->toBe(CarbonImmutable::now()->addSeconds(30)->toDateTimeString())
                ->and($poll->round?->toArray())->toMatchArray(['my_answered' => false, 'opponent_answered' => false]);
        }
    }

    $row = BattleKit::fresh($battle);
    expect([$row->status, $row->end_reason, $row->winner_learner_id, $row->phase, $row->current_round])->toBe([BattleStatus::Finished, BattleEndReason::Completed, $me->id, null, 5])
        ->and($row->finished_at?->toDateTimeString())->toBe(CarbonImmutable::now()->toDateTimeString())
        ->and($row->meta['rounds_played'])->toBe(5)
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([400, 200])
        ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(10);

    $finished = battleEvents('battle.finished');
    expect($finished)->toHaveCount(1)
        ->and($finished[0])->toMatchArray(['battle_id' => $battle->id, 'end_reason' => 'completed', 'result' => 'challenger', 'rounds_played' => 5])
        ->and($finished[0]['duration_ms'])->toBe((int) (CarbonImmutable::now()->getTimestampMs() - $row->starts_at?->getTimestampMs()));

    // The result as each side sees it; the poll of a finished match carries the reason; another poll changes nothing.
    $mine = BattleKit::reader()->view(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    $theirs = BattleKit::reader()->view(BattleKit::reader()->find($friend, $battle->id), $friend, CarbonImmutable::now());
    expect($mine->result)->toMatchArray(['outcome' => 'won', 'end_reason' => 'completed'])
        ->and($mine->result['rounds'] ?? [])->toHaveCount(5)
        ->and($mine->result['rounds'][0] ?? null)->toBe(['no' => 1, 'me' => ['verdict' => 'correct', 'points' => 100], 'opponent' => ['verdict' => 'wrong', 'points' => 0]])
        ->and($mine->result['rounds'][4] ?? null)->toBe(['no' => 5, 'me' => ['verdict' => 'skipped', 'points' => 0], 'opponent' => ['verdict' => 'correct', 'points' => 100]])
        ->and($mine->scores)->toBe(['me' => 400, 'opponent' => 200])
        ->and([$mine->round, $mine->rematch, $mine->opponentPresent, $mine->opponentAbsentSince])->toBe([null, null, true, null])
        ->and($theirs->result['outcome'] ?? null)->toBe('lost')
        ->and($theirs->scores)->toBe(['me' => 200, 'opponent' => 400])
        ->and($theirs->result['rounds'][0] ?? null)->toBe(['no' => 1, 'me' => ['verdict' => 'wrong', 'points' => 0], 'opponent' => ['verdict' => 'correct', 'points' => 100]]);
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now()->addMinutes(5));
    expect([$poll->status, $poll->endReason, $poll->version, $poll->round])->toBe(['finished', 'completed', $version, null])
        ->and(battleEvents('battle.finished'))->toHaveCount(1)
        ->and(battleRefusal(fn () => BattleKit::answer($battle, $me, 5, ['skip' => true])))->toBe(['battle_closed', ['end_reason' => 'completed']]);
});

it('ends in a draw when the scores are equal: no winner, `draw` for both, the event says so', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Sentence, $course);

    for ($round = 1; $round <= 5; $round++) {
        $element = BattleKit::element($battle, $round);
        BattleKit::answer($battle, $me, $round, BattleKit::correct($element));
        BattleKit::answer($battle, $friend, $round, BattleKit::correct($element));
        battleTick(CarbonImmutable::now()->addSeconds(4)->toDateTimeString());
        BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    }

    $row = BattleKit::fresh($battle);
    expect([$row->status, $row->winner_learner_id])->toBe([BattleStatus::Finished, null])
        ->and([$row->participant($me->id)->score, $row->participant($friend->id)->score])->toBe([500, 500])
        ->and(BattleKit::reader()->view($row, $me, CarbonImmutable::now())->result['outcome'] ?? null)->toBe('draw')
        ->and(BattleKit::reader()->view($row, $friend, CarbonImmutable::now())->result['outcome'] ?? null)->toBe('draw')
        ->and(battleEvents('battle.finished')[0]['result'] ?? null)->toEqual('draw');
});

it('lets a player surrender in the countdown or the game: the opponent wins with the scores of the resolved rounds; not in a room', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Collocation, $course);
    expect(battleRefusal(fn () => BattleKit::service()->surrender($me, $room->id)))->toBe(['battle_wrong_state', ['status' => 'room']]);
    BattleKit::service()->cancel($me, $room->id);

    $countdown = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $given = BattleKit::service()->surrender($friend, $countdown->id);
    expect([$given->status, $given->end_reason, $given->winner_learner_id, $given->meta['rounds_played']])->toBe([BattleStatus::Finished, BattleEndReason::Surrender, $me->id, 0])
        ->and(BattleKit::fresh($countdown)->participant($friend->id)->surrendered_at?->toDateTimeString())->toBe(BattleKit::T0)
        ->and(battleEvents('battle.finished')[0] ?? null)->toMatchArray(['battle_id' => $countdown->id, 'end_reason' => 'surrender', 'result' => 'challenger', 'rounds_played' => 0]);

    // In the game: round 1 resolved (100 : 0), my answer to round 2 is in and not revealed; I surrender - the friend wins, round 2 does not count.
    $game = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    BattleKit::answer($game, $me, 1, BattleKit::correct(BattleKit::element($game, 1)));
    BattleKit::answer($game, $friend, 1, BattleKit::wrong(BattleKit::element($game, 1)));
    battleTick(CarbonImmutable::now()->addSeconds(4)->toDateTimeString());
    BattleKit::reader()->poll(BattleKit::reader()->find($me, $game->id), $me, CarbonImmutable::now());
    BattleKit::answer($game, $me, 2, BattleKit::correct(BattleKit::element($game, 2)));

    $given = BattleKit::service()->surrender($me, $game->id);
    $view = BattleKit::reader()->view(BattleKit::fresh($game), $me, CarbonImmutable::now());
    expect([$given->status, $given->end_reason, $given->winner_learner_id, $given->meta['rounds_played']])->toBe([BattleStatus::Finished, BattleEndReason::Surrender, $friend->id, 1])
        ->and($view->result)->toMatchArray(['outcome' => 'lost', 'end_reason' => 'surrender'])
        ->and($view->result['rounds'] ?? [])->toHaveCount(1)
        ->and($view->scores)->toBe(['me' => 100, 'opponent' => 0])
        ->and(battleRefusal(fn () => BattleKit::service()->surrender($friend, $game->id)))->toBe(['battle_closed', ['end_reason' => 'surrender']]);
    // Round 2 for the loser: the review of an unresolved round is not shown.
    expect(BattleKit::reader()->round(BattleKit::fresh($game), $me, 2, CarbonImmutable::now())->reveal)->toBeNull()
        ->and(BattleKit::reader()->round(BattleKit::fresh($game), $me, 1, CarbonImmutable::now())->reveal)->not->toBeNull();
});

it('finishes a game whose opponent is silent for more than 30 s in favour of the one who is here, and closes one both have left', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // I poll, the friend went silent after the acceptance (10:00:00): absent once 30 s have passed (the screens are warned from 10 s on).
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    battleTick('2026-10-30 10:00:30');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    expect([$poll->status, $poll->opponentPresent, $poll->opponentAbsentSince?->toDateTimeString()])->toBe(['playing', false, BattleKit::T0]);
    battleTick('2026-10-30 10:00:31');
    $poll = BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    expect([$poll->status, $poll->endReason])->toBe(['finished', 'absent'])
        ->and(BattleKit::fresh($battle)->winner_learner_id)->toBe($me->id)
        ->and(battleEvents('battle.finished')[0] ?? null)->toMatchArray(['battle_id' => $battle->id, 'end_reason' => 'absent', 'result' => 'challenger', 'rounds_played' => 0])
        ->and(BattleKit::reader()->view(BattleKit::fresh($battle), $friend, CarbonImmutable::now())->result)->toMatchArray(['outcome' => 'lost', 'end_reason' => 'absent']);

    // Both silent: the sweeper closes the match without a result.
    battleTick(BattleKit::T0);
    $both = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    battleTick('2026-10-30 10:00:30');
    expect(app(BattleJanitor::class)->sweep())->toBe(0);
    battleTick('2026-10-30 10:00:31');
    expect(app(BattleJanitor::class)->sweep())->toBe(1);
    $row = BattleKit::fresh($both);
    expect([$row->status, $row->end_reason, $row->winner_learner_id])->toBe([BattleStatus::Closed, BattleEndReason::BothAbsent, null])
        ->and(BattleKit::reader()->view($row, $me, CarbonImmutable::now())->result)->toMatchArray(['outcome' => 'none', 'end_reason' => 'both_absent'])
        ->and(collect(battleEvents('battle.closed'))->last()['end_reason'] ?? null)->toEqual('both_absent');
});

it('writes the presence at most once per 5 s and never moves the version; the poll tells when the opponent is absent and since when', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $writes = fn (array $queries): int => count(QueryLog::matching($queries, '/^update ["`]battle_participants["`] set ["`]last_seen_at["`]/i'));
    $pollNow = fn (Learner $who) => BattleKit::reader()->poll(BattleKit::reader()->find($who, $battle->id), $who, CarbonImmutable::now());

    // The acceptance wrote last_seen_at = 10:00:00 for both; the poll that opened round 1 (10:00:03) was within 5 s and wrote nothing.
    expect(BattleKit::fresh($battle)->participant($me->id)->last_seen_at?->toDateTimeString())->toBe(BattleKit::T0);
    battleTick('2026-10-30 10:00:04');
    expect($writes(QueryLog::during(fn () => $pollNow($me))))->toBe(0);
    battleTick('2026-10-30 10:00:05');
    expect($writes(QueryLog::during(fn () => $pollNow($me))))->toBe(1)
        ->and(BattleKit::fresh($battle)->participant($me->id)->last_seen_at?->toDateTimeString())->toBe('2026-10-30 10:00:05');
    battleTick('2026-10-30 10:00:09');
    expect($writes(QueryLog::during(fn () => $pollNow($me))))->toBe(0)
        ->and(BattleKit::fresh($battle)->state_version)->toBe(4);

    // Two tabs at once (the same second): one write.
    battleTick('2026-10-30 10:00:10');
    $queries = QueryLog::during(function () use ($pollNow, $me): void {
        $pollNow($me);
        $pollNow($me);
    });
    expect($writes($queries))->toBe(1);

    // The friend has been silent since the acceptance (10:00:00): the poll says since when from 10:00:11 on, the match ends at 10:00:31.
    battleTick('2026-10-30 10:00:30');
    $poll = $pollNow($me);
    expect([$poll->opponentPresent, $poll->opponentAbsentSince?->toDateTimeString()])->toBe([false, BattleKit::T0]);
    battleTick('2026-10-30 10:00:31');
    $poll = $pollNow($me);
    expect([$poll->status, $poll->endReason])->toBe(['finished', 'absent']);

    // The poll of a finished match writes no presence.
    battleTick('2026-10-30 10:01:00');
    expect($writes(QueryLog::during(fn () => $pollNow($me))))->toBe(0);
});

it('warns that the opponent lost the connection after 10 s of silence - long before the 30 s that end the match - and says since when; they are present again as soon as they poll', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $pollNow = fn (Learner $who) => BattleKit::reader()->poll(BattleKit::reader()->find($who, $battle->id), $who, CarbonImmutable::now());
    $viewNow = fn (Learner $who) => BattleKit::reader()->view(BattleKit::reader()->find($who, $battle->id), $who, CarbonImmutable::now());
    $state = fn ($read) => [$read->status, $read->opponentPresent, $read->opponentAbsentSince?->toDateTimeString()];

    // The friend's last heartbeat is the acceptance (10:00:00). Silence counts when it is LONGER than 10 s.
    battleTick('2026-10-30 10:00:10');
    expect($state($pollNow($me)))->toBe(['playing', true, null]);
    battleTick('2026-10-30 10:00:11');
    $view = $viewNow($me);
    expect($state($pollNow($me)))->toBe(['playing', false, BattleKit::T0])
        ->and($state($view))->toBe(['playing', false, BattleKit::T0])
        ->and([$view->presenceWarnSeconds, $view->presenceGraceSeconds])->toBe([10, 30]);   // the screen counts 30 - (now - since)

    // They poll once: the heartbeat is written and they are here again; the same moment shows nothing to the other side's rules.
    battleTick('2026-10-30 10:00:20');
    expect($state($pollNow($me)))->toBe(['playing', false, BattleKit::T0]);
    $pollNow($friend);
    battleTick('2026-10-30 10:00:21');
    expect($state($pollNow($me)))->toBe(['playing', true, null]);

    // Gone again: the warning comes 11 s after the heartbeat, the end exactly as before - after 30 s of silence, not a second earlier.
    battleTick('2026-10-30 10:00:31');
    expect($state($pollNow($me)))->toBe(['playing', false, '2026-10-30 10:00:20']);
    battleTick('2026-10-30 10:00:50');
    expect($state($pollNow($me)))->toBe(['playing', false, '2026-10-30 10:00:20']);
    battleTick('2026-10-30 10:00:51');
    $ended = $pollNow($me);
    expect([$ended->status, $ended->endReason, $ended->opponentPresent, $ended->opponentAbsentSince])->toBe(['finished', 'absent', true, null]);   // over: nobody to warn about
});

it('warns about silence in the room and the countdown as well, never for an invitation or a match that is over, and never longer than the grace', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $see = fn (Battle $row, Learner $who) => BattleKit::reader()->view(BattleKit::reader()->find($who, $row->id), $who, CarbonImmutable::now());

    $invited = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson);
    battleTick('2026-10-30 10:00:20');
    expect([$see($invited, $friend)->opponentPresent, $see($invited, $friend)->opponentAbsentSince])->toBe([true, null]);   // nobody is "present" yet

    $room = BattleKit::service()->accept($friend, $invited->id);
    expect($see($room, $me)->opponentPresent)->toBeTrue();
    battleTick('2026-10-30 10:00:31');   // 11 s after the acceptance
    $view = $see($room, $me);
    expect([$view->status, $view->opponentPresent, $view->opponentAbsentSince?->toDateTimeString()])->toBe(['room', false, '2026-10-30 10:00:20']);

    BattleKit::service()->cancel($me, $room->id);
    $over = $see($room, $friend);
    expect([$over->status, $over->opponentPresent, $over->opponentAbsentSince])->toBe(['closed', true, null]);

    // The warning cannot be set to come after the end: it is cut to the grace.
    config(['practice.battle.presence_warn_seconds' => 99]);
    expect(BattleKit::reader()->view(BattleKit::fresh($room), $me, CarbonImmutable::now())->presenceWarnSeconds)->toBe(30);
    config(['practice.battle.presence_warn_seconds' => 7]);
    expect(BattleKit::reader()->view(BattleKit::fresh($room), $me, CarbonImmutable::now())->presenceWarnSeconds)->toBe(7);
});

it('reports a clip that does not play: the file is there - the match goes on; missing - the match is voided; a repeated report answers the same', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Dictation, $course);
    $element = BattleKit::element($battle, 1);
    $requestId = (string) Str::uuid();

    expect(battleRefusal(fn () => BattleKit::service()->audioFailed($me, $battle->id, (string) Str::uuid(), 'eNOPE000000')))->toBe(['unknown_element_id', []]);

    $local = BattleKit::service()->audioFailed($me, $battle->id, $requestId, $element['id']);
    expect([$local->verified, $local->battle->status, $local->battle->state_version])->toBe([false, BattleStatus::Playing, 4])
        ->and(battleEvents('practice.audio_failed'))->toEqual([['battle_id' => $battle->id, 'source' => 'phrase', 'reason' => 'client_report', 'verified' => false]]);
    $repeat = BattleKit::service()->audioFailed($me, $battle->id, $requestId, $element['id']);
    expect($repeat->verified)->toBeFalse()
        ->and(battleEvents('practice.audio_failed'))->toHaveCount(1);

    Storage::disk('practice_audio')->delete($element['audio_path']);
    $voided = BattleKit::service()->audioFailed($friend, $battle->id, (string) Str::uuid(), $element['id']);
    expect([$voided->verified, $voided->battle->status, $voided->battle->end_reason])->toBe([true, BattleStatus::Closed, BattleEndReason::Voided])
        ->and($voided->battle->meta)->toMatchArray(['voided_reason' => 'audio_missing', 'audio_failed_by' => 'opponent'])
        ->and(collect(battleEvents('practice.audio_failed'))->last())->toEqual(['battle_id' => $battle->id, 'source' => 'phrase', 'reason' => 'file_missing', 'verified' => true])
        ->and(BattleKit::fresh($battle)->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(battleRefusal(fn () => BattleKit::service()->audioFailed($me, $battle->id, (string) Str::uuid(), $element['id'])))->toBe(['battle_closed', ['end_reason' => 'voided']]);
});

// ------------------------------------------------------------------------------------------------------------ rematch, list, options, view

it('offers a rematch without a friendship id: the friend, the mode and the topic come from the finished match, and both sides see the offer', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $source = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Sentence, $course, $lesson);
    expect(battleRefusal(fn () => BattleKit::service()->invite($me, null, PracticeMode::Pairs, 'whatever', null, $source->id)))->toBe(['battle_wrong_state', ['status' => 'countdown']]);
    BattleKit::service()->surrender($friend, $source->id);

    $stranger = Learner::factory()->create();
    expect(battleRefusal(fn () => BattleKit::service()->invite($stranger, null, PracticeMode::Pairs, 'whatever', null, $source->id)))->toBe(['battle_not_found', []]);

    $rematch = BattleKit::service()->invite($friend, null, PracticeMode::Pairs, 'whatever', null, $source->id);
    $rematch = BattleKit::fresh($rematch);
    expect([$rematch->status, $rematch->mode, $rematch->course_id, $rematch->lesson_id, $rematch->rematch_of_id, $rematch->friendship_id])->toBe([BattleStatus::Invited, PracticeMode::Sentence, $course->id, $lesson->id, $source->id, $friendship->id])
        ->and([$rematch->challenger_id, $rematch->opponent_id])->toBe([$friend->id, $me->id])
        ->and($rematch->snapshot['elements'])->toHaveCount(5)
        ->and($rematch->snapshot['elements'][0]['id'])->not->toBe($source->snapshot['elements'][0]['id']) // a new issue
        ->and(battleEvents('battle.invited')[1]['rematch'] ?? null)->toBeTrue();

    $mine = BattleKit::reader()->view(BattleKit::reader()->find($me, $source->id), $me, CarbonImmutable::now());
    $theirs = BattleKit::reader()->view(BattleKit::reader()->find($friend, $source->id), $friend, CarbonImmutable::now());
    expect($mine->rematch)->toBe(['battle_id' => $rematch->id, 'status' => 'invited', 'by_me' => false])
        ->and($theirs->rematch)->toBe(['battle_id' => $rematch->id, 'status' => 'invited', 'by_me' => true])
        ->and(BattleKit::reader()->poll(BattleKit::reader()->find($me, $source->id), $me, CarbonImmutable::now())->rematch)->toBe(['battle_id' => $rematch->id, 'status' => 'invited', 'by_me' => false])
        ->and(BattleKit::reader()->list($me, CarbonImmutable::now())->incoming[0] ?? null)->toMatchArray(['id' => $rematch->id, 'rematch' => true]);

    // The friendship is gone: a rematch is refused as not friends.
    $friendship->delete();
    expect(battleRefusal(fn () => BattleKit::service()->invite($me, null, PracticeMode::Pairs, 'whatever', null, $source->id)))->toBe(['not_friends', []]);
});

it('lists the battles of a learner: the one they are busy with, the invitations to them, the last ten that are over - and nothing of anybody else', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create(['first_name' => 'Aziz']);
    $withThird = Friendship::factory()->between($me, $third)->create();

    // Eleven finished matches between me and the friend, the oldest first.
    for ($i = 1; $i <= 11; $i++) {
        battleTick("2026-10-30 09:{$i}:00");
        $done = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
        BattleKit::service()->surrender($i % 2 === 0 ? $me : $friend, $done->id);
    }
    battleTick(BattleKit::T0);
    $incoming = BattleKit::invite($third, $withThird, PracticeMode::Pairs, $course, $lesson);
    $expired = BattleKit::invite($friend, $friendship, PracticeMode::Pairs, $course);
    Battle::query()->whereKey($expired->id)->update(['invite_expires_at' => '2026-10-30 09:59:59']);
    $mine = BattleKit::invite($me, $withThird, PracticeMode::Sentence, $course, $lesson);

    $list = BattleKit::reader()->list($me, CarbonImmutable::now());
    expect($list->active)->toBe(['id' => $mine->id, 'status' => 'invited', 'mode' => 'sentence', 'role' => 'challenger', 'course' => ['slug' => $course->slug, 'title' => 'Английский A1'], 'lesson' => ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'], 'opponent' => ['first_name' => 'Aziz']])
        ->and($list->incoming)->toBe([['id' => $incoming->id, 'mode' => 'pairs', 'from' => ['first_name' => 'Aziz'], 'course' => ['slug' => $course->slug, 'title' => 'Английский A1'], 'lesson' => ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'], 'invite_expires_at' => '2026-10-30T10:05:00.000Z', 'rematch' => false]])
        ->and($list->finished)->toHaveCount(10)
        ->and($list->finished[0])->toMatchArray(['mode' => 'collocation', 'opponent' => ['first_name' => 'Dilnoza'], 'outcome' => 'won', 'scores' => ['me' => 0, 'opponent' => 0], 'finished_at' => '2026-10-30T09:11:00.000Z'])
        ->and($list->finished[1]['outcome'])->toBe('lost')
        ->and(array_column($list->finished, 'finished_at'))->toBe(array_map(fn (int $i): string => sprintf('2026-10-30T09:%02d:00.000Z', $i), range(11, 2)));

    // The friend: the expired invitation of theirs is closed lazily and is not "active"; the stranger sees nothing.
    $theirs = BattleKit::reader()->list($friend, CarbonImmutable::now());
    expect($theirs->active)->toBeNull()
        ->and(BattleKit::fresh($expired)->status)->toBe(BattleStatus::Closed)
        ->and($theirs->incoming)->toBe([])
        ->and($theirs->finished)->toHaveCount(10)
        ->and($theirs->finished[0])->toMatchArray(['id' => $expired->id, 'outcome' => 'none', 'finished_at' => '2026-10-30T10:00:00.000Z']) // closed a moment ago by this very list
        ->and($theirs->finished[1]['outcome'])->toBe('lost');
    $nobody = BattleKit::reader()->list(Learner::factory()->create(), CarbonImmutable::now());
    expect([$nobody->active, $nobody->incoming, $nobody->finished])->toBe([null, [], []]);
});

it('tells the options of a battle with a friend: every format with its state for the material of both languages, the friend, the course, the access of the friend', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world(words: 10);
    config(['practice.modes.dictation' => false]);

    $options = BattleKit::reader()->options($me, $friendship->id, $course->slug, $lesson->id);
    expect($options->friend)->toBe(['first_name' => 'Dilnoza'])
        ->and($options->course)->toBe(['slug' => $course->slug, 'title' => 'Английский A1'])
        ->and($options->lesson)->toBe(['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'])
        ->and($options->friendAccess)->toBe('available')
        ->and($options->modes)->toBe([
            ['mode' => 'pairs', 'state' => 'not_enough', 'available' => 10, 'required' => 20, 'reason' => 'no_words', 'round_seconds' => 45],
            ['mode' => 'collocation', 'state' => 'available', 'available' => 6, 'required' => 5, 'reason' => null, 'round_seconds' => 30],
            ['mode' => 'sentence', 'state' => 'available', 'available' => 6, 'required' => 5, 'reason' => null, 'round_seconds' => 45],
            ['mode' => 'dictation', 'state' => 'disabled', 'available' => 6, 'required' => 5, 'reason' => null, 'round_seconds' => 60],
        ]);

    $pending = Friendship::factory()->between($me, Learner::factory()->create())->pending()->create();
    expect(battleRefusal(fn () => BattleKit::reader()->options($me, $pending->id, $course->slug, null)))->toBe(['not_friends', []])
        ->and(battleRefusal(fn () => BattleKit::reader()->options(Learner::factory()->create(), $friendship->id, $course->slug, null)))->toBe(['friendship_not_found', []])
        ->and(battleRefusal(fn () => BattleKit::reader()->options($me, $friendship->id, 'no-such', null)))->toBe(['course_not_found', []]);

    config(['practice.access' => 'pilot']);
    $me->update(['practice_pilot_at' => now()]);
    expect(BattleKit::reader()->options($me->fresh() ?? $me, $friendship->id, $course->slug, null)->friendAccess)->toBe('no_access');
    config(['practice.battle.enabled' => false]);
    expect(battleRefusal(fn () => BattleKit::reader()->options($me->fresh() ?? $me, $friendship->id, $course->slug, null)))->toBe(['battle_disabled', []]);
});

it('shows the battle to each side in their language with the numbers the client must not guess, and hides it from a stranger', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson);

    expect(battleRefusal(fn () => BattleKit::reader()->find(Learner::factory()->create(), $battle->id)))->toBe(['battle_not_found', []])
        ->and(battleRefusal(fn () => BattleKit::reader()->find($me, 999_999)))->toBe(['battle_not_found', []]);

    $mine = BattleKit::reader()->view(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
    $theirs = BattleKit::reader()->view(BattleKit::reader()->find($friend, $battle->id), $friend, CarbonImmutable::now());
    expect([$mine->id, $mine->status, $mine->endReason, $mine->mode, $mine->role, $mine->version])->toBe([$battle->id, 'invited', null, 'pairs', 'challenger', 0])
        ->and($mine->course)->toBe(['slug' => $course->slug, 'title' => 'Английский A1'])
        ->and($theirs->course)->toBe(['slug' => $course->slug, 'title' => 'Ingliz tili A1'])
        ->and($mine->lesson)->toBe(['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'])
        ->and($mine->opponent)->toBe(['first_name' => 'Dilnoza'])
        ->and($theirs->opponent)->toBe(['first_name' => 'Bek'])
        ->and($theirs->role)->toBe('opponent')
        ->and([$mine->roundsTotal, $mine->roundSeconds, $mine->countdownSeconds, $mine->revealSeconds, $mine->presenceGraceSeconds, $mine->deadlineGraceMs])->toBe([5, 45, 3, 4, 30, 1500])
        ->and($mine->inviteExpiresAt->toDateTimeString())->toBe('2026-10-30 10:05:00')
        ->and([$mine->roomExpiresAt, $mine->startsAt, $mine->round, $mine->result, $mine->rematch, $mine->audioPreload])->toBe([null, null, null, null, null, null])
        ->and($mine->ready)->toBe(['me' => false, 'opponent' => false])
        ->and([$mine->opponentPresent, $mine->opponentAbsentSince])->toBe([true, null])
        ->and($mine->scores)->toBe(['me' => 0, 'opponent' => 0])
        ->and($mine->friendshipId)->toBe($friendship->id)
        ->and($mine->serverNow->toDateTimeString())->toBe(BattleKit::T0);

    // `isDue` is a pure function of the loaded row: no query.
    $loaded = BattleKit::fresh($battle);
    expect(QueryLog::during(fn () => app(BattleClock::class)->isDue($loaded, CarbonImmutable::now())))->toBe([]);
});
