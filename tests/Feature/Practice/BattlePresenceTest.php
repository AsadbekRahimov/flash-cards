<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\BattlePollView;
use App\Domain\Practice\DTO\BattleView;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Practice\BattleKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §8.4 (18.12a): the outcome of an absence is the clock's, not the order of the requests. Every door into a match - the
 * poll, the match, the round, the list, an answer, `ready`, a surrender, an audio report - first moves the match to `now` by the
 * presence as it WAS, and only then, if the match is still on, writes the presence of the one who asked. So the first player to
 * come back after both left closes the match as `both_absent` (no winner) instead of winning by the absence of the other, and a
 * player away for longer than the grace loses to one who was away less, whoever asks first - the very outcomes the sweeper gives.
 *
 * The world of BattleKit: the acceptance marks both players present at 10:00:00 (T0), the match is on from 10:00:03; a poll
 * writes the presence when the mark is 5 s old or more; the grace is 30 s, the warning 10 s; a collocation round lasts 30 s.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return list<array<string, mixed>> the props of the events with the name, in order */
function presenceEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** Runs a closure that must refuse; returns [code, details]. @return array{0: string, 1: array<string, mixed>} */
function presenceRefusal(Closure $fn): array
{
    try {
        $fn();
    } catch (BattleException|PracticeException $e) {
        return [$e->errorCode, $e->details];
    }

    throw new RuntimeException('Nothing was refused.');
}

function presenceAt(string $time): CarbonImmutable
{
    CarbonImmutable::setTestNow($time);

    return CarbonImmutable::now();
}

/** The `last_seen_at` of the two players as stored, the challenger first. @return list<string|null> */
function presenceMarks(Battle $battle): array
{
    $row = BattleKit::fresh($battle);

    return [$row->participant($row->challenger_id)->last_seen_at?->toDateTimeString(), $row->participant($row->opponent_id)->last_seen_at?->toDateTimeString()];
}

/** What the sweeper and every door must agree on once a match is over. @return array<string, mixed> */
function presenceOutcome(Battle $battle): array
{
    $row = BattleKit::fresh($battle);

    return [
        'status' => $row->status->value,
        'end_reason' => $row->end_reason?->value,
        'winner' => $row->winner_learner_id,
        'left' => $row->meta['left'] ?? null,
        'rounds_played' => $row->meta['rounds_played'] ?? null,
        'active' => $row->participants->pluck('active_learner_id')->all(),
        'marks' => presenceMarks($battle),
    ];
}

function presencePoll(Battle $battle, Learner $who): BattlePollView
{
    return BattleKit::reader()->poll(BattleKit::reader()->find($who, $battle->id), $who, CarbonImmutable::now());
}

function presenceView(Battle $battle, Learner $who): BattleView
{
    return BattleKit::reader()->view(BattleKit::reader()->find($who, $battle->id), $who, CarbonImmutable::now());
}

/** The heartbeat statements among the queries. @param list<string> $queries */
function presenceWrites(array $queries): int
{
    return count(QueryLog::matching($queries, '/^update ["`]battle_participants["`] set ["`]last_seen_at["`]/i'));
}

// ------------------------------------------------------------------------------------------------------------ both absent

it('closes a game both have left as both_absent on the first request after the grace, through whichever door and before the sweeper: no winner, one event, no heartbeat for either', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // Every door, opened by the one who comes back first (31 s after both were last here); the second column is the mode of the match.
    $doors = [
        'poll' => [PracticeMode::Collocation, function (Battle $battle, Learner $who): void {
            $queries = QueryLog::during(function () use ($battle, $who, &$poll): void {
                $poll = presencePoll($battle, $who);
            });
            expect([$poll->status, $poll->endReason, $poll->opponentPresent, $poll->opponentAbsentSince, $poll->rematch])->toBe(['closed', 'both_absent', true, null, null])
                // Within the budget of a poll with a transition (docs/16 §13: 9 on the service), and the viewer is NOT marked present.
                ->and(count($queries))->toBeLessThanOrEqual(9, implode("\n", $queries))
                ->and(presenceWrites($queries))->toBe(0);
        }],
        'view' => [PracticeMode::Pairs, function (Battle $battle, Learner $who): void {
            $view = presenceView($battle, $who);
            expect([$view->status, $view->endReason, $view->rematch, $view->opponentPresent, $view->opponentAbsentSince])->toBe(['closed', 'both_absent', null, true, null])
                ->and($view->result)->toMatchArray(['outcome' => 'none', 'end_reason' => 'both_absent', 'rounds' => []]);
        }],
        'round' => [PracticeMode::Sentence, function (Battle $battle, Learner $who): void {
            $round = BattleKit::reader()->round(BattleKit::reader()->find($who, $battle->id), $who, 1, CarbonImmutable::now());
            expect([$round->no, $round->phase, $round->reveal])->toBe([1, null, null]);
        }],
        'list' => [PracticeMode::Collocation, function (Battle $battle, Learner $who): void {
            $list = BattleKit::reader()->list($who, CarbonImmutable::now());
            expect($list->active)->toBeNull()
                ->and($list->finished[0] ?? null)->toMatchArray(['id' => $battle->id, 'outcome' => 'none']);
        }],
        'answer' => [PracticeMode::Collocation, function (Battle $battle, Learner $who): void {
            expect(presenceRefusal(fn () => BattleKit::answer($battle, $who, 1, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['battle_closed', ['end_reason' => 'both_absent']])
                ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(0);
        }],
        'surrender' => [PracticeMode::Collocation, function (Battle $battle, Learner $who): void {
            expect(presenceRefusal(fn () => BattleKit::service()->surrender($who, $battle->id)))->toBe(['battle_closed', ['end_reason' => 'both_absent']])
                ->and(BattleKit::fresh($battle)->participant($who->id)->surrendered_at)->toBeNull();
        }],
        'audio-failed' => [PracticeMode::Dictation, function (Battle $battle, Learner $who): void {
            $element = BattleKit::element($battle, 1);
            expect(presenceRefusal(fn () => BattleKit::service()->audioFailed($who, $battle->id, (string) Str::uuid(), (string) $element['id'])))->toBe(['battle_closed', ['end_reason' => 'both_absent']]);
        }],
    ];

    $closed = 0;
    foreach ($doors as $door => [$mode, $comeBack]) {
        presenceAt(BattleKit::T0);
        $battle = BattleKit::playing($me, $friend, $friendship, $mode, $course);
        expect(presenceMarks($battle))->toBe([BattleKit::T0, BattleKit::T0], $door);
        // The one who comes back first is the challenger for one half of the doors and the opponent for the other: the rule knows no role.
        $first = $closed % 2 === 0 ? $me : $friend;
        $later = $first === $me ? $friend : $me;

        presenceAt('2026-10-30 10:00:31');
        $comeBack($battle, $first);
        $closed++;

        expect(presenceOutcome($battle))->toBe([
            'status' => 'closed', 'end_reason' => 'both_absent', 'winner' => null, 'left' => null, 'rounds_played' => 0,
            'active' => [null, null], 'marks' => [BattleKit::T0, BattleKit::T0],
        ], $door)
            ->and(presenceEvents('battle.closed'))->toHaveCount($closed)
            ->and(presenceEvents('battle.closed')[$closed - 1])->toMatchArray(['battle_id' => $battle->id, 'end_reason' => 'both_absent'])
            ->and(presenceEvents('battle.finished'))->toBe([]);

        // The one who comes later reads the same, writes no presence, raises no second event; the sweeper finds nothing to do.
        presenceAt('2026-10-30 10:00:40');
        $view = presenceView($battle, $later);
        expect([$view->status, $view->endReason])->toBe(['closed', 'both_absent'], $door)
            ->and($view->result)->toMatchArray(['outcome' => 'none', 'end_reason' => 'both_absent'])
            ->and(presenceMarks($battle))->toBe([BattleKit::T0, BattleKit::T0], $door)
            ->and(app(BattleJanitor::class)->sweep())->toBe(0)
            ->and(presenceEvents('battle.closed'))->toHaveCount($closed);
    }
});

it('answers a repeated request_id with the stored verdict even when that very request closed the match both had left', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $element = BattleKit::element($battle, 1);
    $request = (string) Str::uuid();

    presenceAt('2026-10-30 10:00:10');
    $first = BattleKit::answer($battle, $me, 1, BattleKit::correct($element), $request);
    expect([$first->verdict->value, $first->points, $first->roundResolved])->toBe(['correct', 100, false])
        ->and(presenceMarks($battle))->toBe(['2026-10-30 10:00:10', BattleKit::T0]); // the answer of a player still in is their heartbeat

    // Both silent since: 35 s for me, 45 s for the friend. My repeat closes the match (both_absent) and is still answered as the first time.
    presenceAt('2026-10-30 10:00:45');
    $repeat = BattleKit::answer($battle, $me, 1, BattleKit::correct($element), $request);
    expect([$repeat->verdict->value, $repeat->points, $repeat->roundResolved, $repeat->acceptedAt->toDateTimeString()])->toBe(['correct', 100, true, '2026-10-30 10:00:10'])
        ->and([$repeat->poll->status, $repeat->poll->endReason])->toBe(['closed', 'both_absent'])
        ->and(presenceOutcome($battle))->toMatchArray(['status' => 'closed', 'end_reason' => 'both_absent', 'winner' => null, 'rounds_played' => 0, 'marks' => ['2026-10-30 10:00:10', BattleKit::T0]])
        ->and(presenceEvents('battle.closed'))->toHaveCount(1);

    // A new request to the same round is "the battle is closed"; the row of the first answer stands alone.
    expect(presenceRefusal(fn () => BattleKit::answer($battle, $me, 1, BattleKit::correct($element))))->toBe(['battle_closed', ['end_reason' => 'both_absent']])
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(1);
});

// ------------------------------------------------------------------------------------------------------------ one absent

it('finishes a game against the player who was away longer than the grace when THEY come back first - by a poll or an answer - in favour of the one who was away less; at exactly the grace they are still in', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // The boundary: my mark is 10:00:00, the friend's 10:00:11. At exactly 30 s of silence I am still here - my poll is my heartbeat and the match goes on.
    $edge = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:11');
    presencePoll($edge, $friend);
    presenceAt('2026-10-30 10:00:30');
    $poll = presencePoll($edge, $me);
    expect([$poll->status, $poll->opponentPresent, $poll->opponentAbsentSince?->toDateTimeString()])->toBe(['playing', false, '2026-10-30 10:00:11'])
        ->and(presenceMarks($edge))->toBe(['2026-10-30 10:00:30', '2026-10-30 10:00:11']);
    BattleKit::service()->surrender($me, $edge->id); // out of the way

    // A second over the grace, I come back first with a poll: I lose, the friend (silent 11 s) wins; my request wrote no presence.
    presenceAt(BattleKit::T0);
    $byPoll = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:20');
    presencePoll($byPoll, $friend);
    presenceAt('2026-10-30 10:00:31');
    $poll = presencePoll($byPoll, $me);
    expect([$poll->status, $poll->endReason, $poll->opponentPresent, $poll->opponentAbsentSince])->toBe(['finished', 'absent', true, null])
        ->and(presenceOutcome($byPoll))->toBe([
            'status' => 'finished', 'end_reason' => 'absent', 'winner' => $friend->id, 'left' => null, 'rounds_played' => 0,
            'active' => [null, null], 'marks' => [BattleKit::T0, '2026-10-30 10:00:20'],
        ])
        ->and(presenceView($byPoll, $me)->result)->toMatchArray(['outcome' => 'lost', 'end_reason' => 'absent'])
        ->and(presenceView($byPoll, $friend)->result)->toMatchArray(['outcome' => 'won', 'end_reason' => 'absent'])
        ->and(presenceEvents('battle.finished'))->toHaveCount(2) // the surrender above and this one
        ->and(presenceEvents('battle.finished')[1])->toMatchArray(['battle_id' => $byPoll->id, 'end_reason' => 'absent', 'result' => 'opponent', 'rounds_played' => 0]);

    // The same with an answer: refused as of a finished match, no row, the friend wins.
    presenceAt(BattleKit::T0);
    $byAnswer = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:20');
    presencePoll($byAnswer, $friend);
    presenceAt('2026-10-30 10:00:31');
    expect(presenceRefusal(fn () => BattleKit::answer($byAnswer, $me, 1, BattleKit::correct(BattleKit::element($byAnswer, 1)))))->toBe(['battle_closed', ['end_reason' => 'absent']])
        ->and(BattleAnswer::query()->where('battle_id', $byAnswer->id)->count())->toBe(0)
        ->and(presenceOutcome($byAnswer))->toMatchArray(['status' => 'finished', 'end_reason' => 'absent', 'winner' => $friend->id, 'marks' => [BattleKit::T0, '2026-10-30 10:00:20']]);

    // The absence comes before the round: past the deadline and its grace as well (10:00:34.5), the match ends by my absence, no time-out is written.
    presenceAt(BattleKit::T0);
    $late = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:20');
    presencePoll($late, $friend);
    presenceAt('2026-10-30 10:00:35');
    $poll = presencePoll($late, $me);
    expect([$poll->status, $poll->endReason])->toBe(['finished', 'absent'])
        ->and(BattleKit::fresh($late)->winner_learner_id)->toBe($friend->id)
        ->and(BattleAnswer::query()->where('battle_id', $late->id)->count())->toBe(0);
});

it('gives the very outcome the sweeper gives - whoever comes back first, both absent or one', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    [$a, $b] = [Learner::factory()->create(['interface_locale' => 'ru']), Learner::factory()->create(['interface_locale' => 'uz'])];
    $other = Friendship::factory()->between($a, $b)->create();

    // A match that is on since 10:00:03, the players last here at 10:00:00 - unless the opponent polls at `$opponentPollsAt`.
    $make = function (Learner $x, Learner $y, Friendship $pair, ?string $opponentPollsAt) use ($course): Battle {
        presenceAt(BattleKit::T0);
        $battle = BattleKit::playing($x, $y, $pair, PracticeMode::Collocation, $course);
        if ($opponentPollsAt !== null) {
            presenceAt($opponentPollsAt);
            presencePoll($battle, $y);
        }

        return $battle;
    };
    // The winner as a role, so that two matches of two pairs compare.
    $outcome = function (Battle $battle): array {
        $row = BattleKit::fresh($battle);

        return [...presenceOutcome($battle), 'winner' => $row->winner_learner_id === null ? null : ($row->winner_learner_id === $row->opponent_id ? 'opponent' : 'challenger')];
    };

    // Two identical matches at 10:00:31: to one the sweeper comes first, to the other the absent challenger does.
    foreach (['both' => null, 'one' => '2026-10-30 10:00:20'] as $case => $opponentPollsAt) {
        $swept = $make($me, $friend, $friendship, $opponentPollsAt);
        $asked = $make($a, $b, $other, $opponentPollsAt);

        presenceAt('2026-10-30 10:00:31');
        presencePoll($asked, $a); // the absent one comes back first
        expect(app(BattleJanitor::class)->sweep())->toBe(1, $case); // only the match nobody asked about is left to the sweeper

        expect($outcome($asked))->toBe($outcome($swept), $case)
            ->and($outcome($asked)['end_reason'])->toBe($case === 'both' ? 'both_absent' : 'absent', $case)
            ->and($outcome($asked)['winner'])->toBe($case === 'both' ? null : 'opponent', $case);
    }
});

// ------------------------------------------------------------------------------------------------------------ the room and the countdown

it('cancels a room when the player who was away longer than the grace presses ready - the match names them as the one who left - and a countdown nobody came back to', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // The room: the friend is here (polled at 10:00:20), I have been silent since the acceptance. My `ready` at 10:00:31 is too late: canceled, I am the one who left.
    $room = BattleKit::room($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:20');
    presencePoll($room, $friend);
    presenceAt('2026-10-30 10:00:31');
    expect(presenceRefusal(fn () => BattleKit::service()->ready($me, $room->id)))->toBe(['battle_closed', ['end_reason' => 'canceled']])
        ->and(presenceOutcome($room))->toBe([
            'status' => 'closed', 'end_reason' => 'canceled', 'winner' => null, 'left' => 'challenger', 'rounds_played' => 0,
            'active' => [null, null], 'marks' => [BattleKit::T0, '2026-10-30 10:00:20'],
        ])
        ->and(BattleKit::fresh($room)->participant($me->id)->ready_at)->toBeNull()
        ->and(presenceEvents('battle.closed')[0] ?? null)->toMatchArray(['battle_id' => $room->id, 'end_reason' => 'canceled'])
        ->and([presenceView($room, $friend)->status, presenceView($room, $friend)->endReason])->toBe(['closed', 'canceled']);

    // In time (29 s of silence) the same `ready` is taken, and it is my heartbeat.
    presenceAt(BattleKit::T0);
    $inTime = BattleKit::room($me, $friend, $friendship, PracticeMode::Collocation, $course);
    presenceAt('2026-10-30 10:00:20');
    presencePoll($inTime, $friend);
    presenceAt('2026-10-30 10:00:29');
    $ready = BattleKit::service()->ready($me, $inTime->id);
    expect([$ready->status, $ready->participant($me->id)->ready_at?->toDateTimeString()])->toBe([BattleStatus::Room, '2026-10-30 10:00:29'])
        ->and(presenceMarks($inTime))->toBe(['2026-10-30 10:00:29', '2026-10-30 10:00:20']);
    BattleKit::service()->cancel($me, $inTime->id);

    // The countdown: both pressed ready at 10:00:00 and nobody polled since; the first poll at 10:00:31 finds both gone - canceled, not a game.
    presenceAt(BattleKit::T0);
    $countdown = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
    expect([$countdown->status, presenceMarks($countdown)])->toBe([BattleStatus::Countdown, [BattleKit::T0, BattleKit::T0]]);
    presenceAt('2026-10-30 10:00:31');
    $poll = presencePoll($countdown, $friend);
    expect([$poll->status, $poll->endReason])->toBe(['closed', 'canceled'])
        ->and(presenceOutcome($countdown))->toMatchArray(['status' => 'closed', 'end_reason' => 'canceled', 'winner' => null, 'rounds_played' => 0, 'marks' => [BattleKit::T0, BattleKit::T0]])
        ->and(BattleKit::fresh($countdown)->meta['left'])->toBeIn(['challenger', 'opponent']);
});

// ------------------------------------------------------------------------------------------------------------ players who are here

it('changes nothing for players who are here: a mark that is old but within the grace is renewed after the clock has had its say, the round moves on, the answer at the deadline is taken as before', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course); // round 1: deadline 10:00:33, answers taken until 10:00:34.5

    // 25 s of silence is within the grace: my poll is taken as presence (written: the mark was 5 s old or more) and the match goes on.
    presenceAt('2026-10-30 10:00:25');
    $queries = QueryLog::during(function () use ($battle, $me, &$poll): void {
        $poll = presencePoll($battle, $me);
    });
    expect([$poll->status, $poll->opponentPresent])->toBe(['playing', false])
        ->and(presenceWrites($queries))->toBe(1)
        ->and(count($queries))->toBeLessThanOrEqual(3, implode("\n", $queries)) // the poll without a transition: battle, participants, the heartbeat
        ->and(presenceMarks($battle))->toBe(['2026-10-30 10:00:25', BattleKit::T0]);
    presenceAt('2026-10-30 10:00:28');
    presencePoll($battle, $friend);
    expect(presenceMarks($battle))->toBe(['2026-10-30 10:00:25', '2026-10-30 10:00:28']);

    // The deadline: my answer 100 ms before the end of the grace is taken (and is my heartbeat); the friend's at the end finds the round
    // resolved by a time-out - round_closed, as always; their request is a heartbeat all the same (the match is on).
    presenceAt('2026-10-30 10:00:34.4');
    $mine = BattleKit::answer($battle, $me, 1, BattleKit::correct(BattleKit::element($battle, 1)));
    expect([$mine->verdict->value, $mine->points, $mine->roundResolved])->toBe(['correct', 100, false])
        ->and(presenceMarks($battle))->toBe(['2026-10-30 10:00:34', '2026-10-30 10:00:28']);
    presenceAt('2026-10-30 10:00:34.5');
    expect(presenceRefusal(fn () => BattleKit::answer($battle, $friend, 1, BattleKit::correct(BattleKit::element($battle, 1)))))->toBe(['round_closed', ['round' => 1, 'phase' => 'reveal']]);
    $row = BattleKit::fresh($battle);
    expect([$row->status, $row->phase, $row->current_round])->toBe([BattleStatus::Playing, BattlePhase::Reveal, 1])
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->where('learner_id', $friend->id)->firstOrFail()->verdict)->toBe(Verdict::Timeout)
        ->and(presenceMarks($battle))->toBe(['2026-10-30 10:00:34', '2026-10-30 10:00:34']);

    // The reveal is over: the poll of a player whose mark is 5 s old moves the match to round 2 FIRST and writes the presence after - once.
    presenceAt('2026-10-30 10:00:39');
    $queries = QueryLog::during(function () use ($battle, $me, &$poll): void {
        $poll = presencePoll($battle, $me);
    });
    expect([$poll->status, $poll->round?->no, $poll->round?->phase])->toBe(['playing', 2, 'answering'])
        ->and(presenceWrites($queries))->toBe(1)
        ->and(count($queries))->toBeLessThanOrEqual(9, implode("\n", $queries))
        ->and(presenceMarks($battle))->toBe(['2026-10-30 10:00:39', '2026-10-30 10:00:34'])
        ->and(presenceEvents('battle.finished'))->toBe([])
        ->and(presenceEvents('battle.closed'))->toBe([]);
});

it('takes the floor of the grace into the clock and the screens: a grace of 5 from the environment is 15 - heartbeat_write_seconds plus the longest pause of the poll - and the warning stays below it', function (): void {
    config(['practice.battle.presence_grace_seconds' => 5]);
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);

    // 14 s of the friend's silence: warned (10 s), not over; the screen is told the effective grace.
    presenceAt('2026-10-30 10:00:14');
    $view = presenceView($battle, $me);
    expect([$view->status, $view->opponentPresent, $view->opponentAbsentSince?->toDateTimeString()])->toBe(['playing', false, BattleKit::T0])
        ->and([$view->presenceGraceSeconds, $view->presenceWarnSeconds])->toBe([15, 10]);

    // Exactly 15 s: still in. 16 s: the match is over in my favour (I polled 2 s ago).
    presenceAt('2026-10-30 10:00:15');
    expect(presencePoll($battle, $me)->status)->toBe('playing');
    presenceAt('2026-10-30 10:00:16');
    $poll = presencePoll($battle, $me);
    expect([$poll->status, $poll->endReason])->toBe(['finished', 'absent'])
        ->and(BattleKit::fresh($battle)->winner_learner_id)->toBe($me->id);
});
