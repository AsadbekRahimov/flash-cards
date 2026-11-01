<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * A whole match of two players over HTTP, the way the two TWAs play it (docs/16 §7, §8, §14.4): the invitation, the incoming list of the
 * friend, the acceptance, the clips of a dictation, both ready, the countdown, five rounds (a right answer, a wrong one, a round that
 * runs out), the review of each round read from both sides, the end - and the SAME result on both screens, mirrored. Then a rematch.
 * Every mode; nothing but the routes of the API and the clock.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Time goes on by `$seconds`; both screens poll (they are here), whatever was due happens. */
function matchWait(int $id, Learner $a, Learner $b, float $seconds): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds((int) ($seconds * 1000)));
    foreach ([$a, $b] as $player) {
        PracticeKit::resetLimiters($player);
        BattleHttp::poll($player, $id)->assertOk();
    }
}

/** The clock goes 2 s past the deadline of the round and its grace, in steps the screens keep up with by polling. */
function matchRunOut(int $id, Learner $a, Learner $b): void
{
    $until = (BattleKit::fresh($id)->round_deadline_at ?? throw new RuntimeException('no deadline'))->addSeconds(2);
    while (CarbonImmutable::now()->lt($until)) {
        $step = min(15.0, (float) CarbonImmutable::now()->diffInSeconds($until, true));
        matchWait($id, $a, $b, max($step, 0.5));
    }
}

dataset('match modes', ['pairs', 'collocation', 'sentence', 'dictation']);

it('plays a whole match of two players over HTTP, in every mode, to the same result on both screens - and then a rematch', function (string $modeName): void {
    $mode = PracticeMode::from($modeName);
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // The invitation. The friend finds it among the incoming ones, opens it, accepts it.
    $created = BattleHttp::invite($me, $friendship, $mode, $course, $lesson)->assertCreated();
    $id = (int) $created->json('id');
    expect($created->json('status'))->toBe('invited');
    $incoming = BattleHttp::get($friend, '')->assertOk()->json('incoming');
    expect(array_column($incoming, 'id'))->toBe([$id]);
    BattleHttp::view($friend, $id)->assertOk()->assertJsonPath('role', 'opponent')->assertJsonPath('status', 'invited');
    $room = BattleHttp::accept($friend, $id)->assertOk();
    expect($room->json('status'))->toBe('room');
    BattleHttp::poll($me, $id)->assertOk()->assertJsonPath('status', 'room');   // the challenger's screen sees it with the next poll

    // The room: a dictation loads its five clips before "Ready" (the list is in the battle), the others have nothing to load.
    $preload = BattleHttp::view($me, $id)->json('audio_preload');
    if ($mode === PracticeMode::Dictation) {
        expect($preload)->toHaveCount(5);
        foreach ($preload as $url) {
            BattleHttp::audio($me, $url)->assertOk();
            BattleHttp::audio($friend, $url)->assertOk();
        }
    } else {
        expect($preload)->toBeNull();
    }

    // Ready, one after the other: the second makes the countdown. The clock goes to its end; a poll starts the game.
    BattleHttp::ready($me, $id)->assertOk()->assertJsonPath('status', 'room');
    BattleHttp::poll($friend, $id)->assertOk()->assertJsonPath('ready.opponent', true);
    $countdown = BattleHttp::ready($friend, $id)->assertOk();
    expect($countdown->json('status'))->toBe('countdown');
    CarbonImmutable::setTestNow(CarbonImmutable::parse((string) $countdown->json('starts_at')));
    foreach ([$me, $friend] as $player) {
        BattleHttp::poll($player, $id)->assertOk()->assertJsonPath('status', 'playing')->assertJsonPath('round.no', 1)->assertJsonPath('round.phase', 'answering');
    }

    // Five rounds. [what I do, what the friend does]: right / wrong / none (runs out).
    $script = [1 => ['right', 'right'], 2 => ['right', 'wrong'], 3 => ['wrong', 'right'], 4 => ['right', 'none'], 5 => ['right', 'right']];
    $points = ['me' => [], 'friend' => []];
    foreach ($script as $round => [$mine, $theirs]) {
        $row = BattleKit::fresh($id);
        expect([$row->status, $row->current_round])->toBe([BattleStatus::Playing, $round]);
        $element = BattleKit::element($row, $round);

        // Both screens read the round the poll brought: the same element, in their own words, without a review.
        $mineRound = BattleHttp::round($me, $id, $round)->assertOk();
        $theirsRound = BattleHttp::round($friend, $id, $round)->assertOk();
        expect($mineRound->json('element.id'))->toBe($theirsRound->json('element.id'))
            ->and([$mineRound->json('phase'), $mineRound->json('reveal'), $theirsRound->json('reveal')])->toBe(['answering', null, null]);
        if ($mode === PracticeMode::Dictation) {
            expect($mineRound->json('element.audio_url'))->toBe($preload[$round - 1]);   // the blob loaded in the room plays the round
        }

        // The answers. Whoever answers second resolves the round; if one never answers, the clock does it after the deadline and its grace.
        foreach ([[$me, $mine], [$friend, $theirs]] as [$player, $kind]) {
            if ($kind === 'none') {
                continue;
            }
            $answer = BattleHttp::answer($player, $id, $round, $kind === 'right' ? BattleKit::correct($element) : BattleKit::wrong($element))->assertOk();
            expect($answer->json('verdict'))->toBe($kind === 'right' ? 'correct' : 'wrong');
        }
        if ($theirs === 'none') {
            expect(BattleHttp::poll($me, $id)->json('round.phase'))->toBe('answering');   // still open: the friend may yet answer
            matchRunOut($id, $me, $friend);
        }

        // The review, from both sides, mirrored; the points of the round are the same facts seen from the other end.
        $mineReveal = BattleHttp::round($me, $id, $round)->assertOk();
        $theirsReveal = BattleHttp::round($friend, $id, $round)->assertOk();
        expect($mineReveal->json('phase'))->toBe('reveal')
            ->and($mineReveal->json('reveal.correct'))->toBe($theirsReveal->json('reveal.correct'))
            ->and($mineReveal->json('reveal.me'))->toBe($theirsReveal->json('reveal.opponent'))
            ->and($mineReveal->json('reveal.opponent'))->toBe($theirsReveal->json('reveal.me'))
            ->and($mineReveal->json('reveal.me.verdict'))->toBe($mine === 'right' ? 'correct' : 'wrong')
            ->and($mineReveal->json('reveal.opponent.verdict'))->toBe(match ($theirs) {
                'right' => 'correct', 'wrong' => 'wrong', default => 'timeout'
            });
        $points['me'][$round] = $mineReveal->json('reveal.me.points');
        $points['friend'][$round] = $mineReveal->json('reveal.opponent.points');

        // The score on both screens: the sum of the rounds that are resolved, mirrored.
        $score = ['me' => array_sum($points['me']), 'opponent' => array_sum($points['friend'])];
        expect(BattleHttp::poll($me, $id)->json('scores'))->toBe($score)
            ->and(BattleHttp::poll($friend, $id)->json('scores'))->toBe(['me' => $score['opponent'], 'opponent' => $score['me']]);

        matchWait($id, $me, $friend, 4);   // the review is over: the next round, or the end
    }

    // The end. The same result on both screens, mirrored: the scores, the outcome, the rounds with what each gave.
    $mineOver = BattleHttp::view($me, $id)->assertOk();
    $theirsOver = BattleHttp::view($friend, $id)->assertOk();
    $total = ['me' => array_sum($points['me']), 'friend' => array_sum($points['friend'])];
    $outcome = $total['me'] === $total['friend'] ? ['draw', 'draw'] : ($total['me'] > $total['friend'] ? ['won', 'lost'] : ['lost', 'won']);
    expect([$mineOver->json('status'), $mineOver->json('end_reason'), $theirsOver->json('status')])->toBe(['finished', 'completed', 'finished'])
        ->and($mineOver->json('scores'))->toBe(['me' => $total['me'], 'opponent' => $total['friend']])
        ->and($theirsOver->json('scores'))->toBe(['me' => $total['friend'], 'opponent' => $total['me']])
        ->and([$mineOver->json('result.outcome'), $theirsOver->json('result.outcome')])->toBe($outcome)
        ->and($mineOver->json('result.rounds'))->toHaveCount(5);
    foreach ($mineOver->json('result.rounds') as $index => $row) {
        $round = $index + 1;
        $mirror = $theirsOver->json("result.rounds.{$index}");
        expect($row['no'])->toBe($round)
            ->and($row['me']['points'])->toBe($points['me'][$round])
            ->and($row['opponent']['points'])->toBe($points['friend'][$round])
            ->and($mirror)->toBe(['no' => $round, 'me' => $row['opponent'], 'opponent' => $row['me']]);
    }
    expect($mineOver->json('result.rounds.3.opponent'))->toBe(['verdict' => 'timeout', 'points' => 0]);

    // The lists: nothing in hand, a row of "finished" for each with the same score from each end.
    $mineList = BattleHttp::get($me, '')->assertOk();
    $theirsList = BattleHttp::get($friend, '')->assertOk();
    expect($mineList->json('active'))->toBeNull()
        ->and($theirsList->json('active'))->toBeNull()
        ->and($mineList->json('finished.0.scores'))->toBe(['me' => $total['me'], 'opponent' => $total['friend']])
        ->and($theirsList->json('finished.0.scores'))->toBe(['me' => $total['friend'], 'opponent' => $total['me']])
        ->and([$mineList->json('finished.0.outcome'), $theirsList->json('finished.0.outcome')])->toBe($outcome);

    // What the server keeps: one row per player per round (the time-out is a row), the players let go, the winner by the score.
    $row = Battle::query()->with('participants')->findOrFail($id);
    expect(BattleAnswer::query()->where('battle_id', $id)->count())->toBe(10)
        ->and(BattleAnswer::query()->where('battle_id', $id)->where('verdict', 'timeout')->count())->toBe(1)
        ->and(BattleParticipant::query()->where('battle_id', $id)->whereNotNull('active_learner_id')->count())->toBe(0)
        ->and($row->winner_learner_id)->toBe($total['me'] === $total['friend'] ? null : ($total['me'] > $total['friend'] ? $me->id : $friend->id));

    // A rematch: the friend offers it, I see the offer on the result screen (the poll of a finished match carries it) and accept.
    $offered = (int) BattleHttp::rematch($friend, $id)->assertCreated()->json('id');
    $seen = BattleHttp::poll($me, $id)->assertOk();
    expect($seen->json('rematch'))->toBe(['battle_id' => $offered, 'status' => 'invited', 'by_me' => false]);
    BattleHttp::accept($me, $offered)->assertOk()->assertJsonPath('status', 'room')->assertJsonPath('mode', $modeName);
    expect(BattleHttp::view($friend, $offered)->json('status'))->toBe('room');
})->with('match modes');
