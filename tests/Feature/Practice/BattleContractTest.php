<?php

declare(strict_types=1);

/*
 * JSON contract of the battle API (docs/16 §7.3-7.4, resources/twa/src/api/types.ts): the exact key structure of every answer for every
 * status of a battle, no `data` envelope, the poll under 600 bytes, `reveal: null` until a round is resolved, the opponent's answer and
 * the score of a round that is not resolved not on the device, no key of the snapshot in a response, `details` inside `error` where
 * docs/16 §7.6 has them. If a test here fails, the TWA client breaks - change the client and docs/04 together.
 *
 * The battle is played over HTTP exactly as the TWA plays it (BattleHttp); BattleKit only READS what the server keeps (the snapshot) to
 * build a right or a wrong answer - no client ever gets it.
 */

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Models\Battle;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;

uses(RefreshDatabase::class);

const BATTLE_CONTRACT_COURSE = ['slug', 'title'];
const BATTLE_CONTRACT_LESSON = ['id', 'stage', 'number', 'title'];
const BATTLE_CONTRACT_ROUND_STATE = ['no', 'phase', 'deadline_at', 'phase_ends_at', 'my_answered', 'opponent_answered'];
const BATTLE_CONTRACT_REMATCH = ['battle_id', 'status', 'by_me'];
const BATTLE_CONTRACT_SIDE = ['answer', 'verdict', 'points'];
const BATTLE_CONTRACT_POLL_KEYS = ['id', 'status', 'version', 'server_now', 'ready', 'opponent_present', 'opponent_absent_since', 'round', 'scores', 'end_reason', 'rematch'];

/** The keys that live in the snapshot only (docs/16 §2.8): they must not appear ANYWHERE in a response before the review of a round. */
const BATTLE_CONTRACT_FORBIDDEN_KEYS = [
    'pairs', 'correct_ids', 'accepted', 'accepted_display', 'expected', 'full_text', 'correct_text', 'correct_option_ids',
    'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id', 'pool', 'seed', 'snapshot',
];

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake(); // the DM of an invitation is BattleMessengerTest's
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The structure of the battle as the TWA reads it; the flags say which nested parts are objects (a part that is null is only a key).
 *
 * @return array<int|string, mixed>
 */
function battleShape(bool $lesson = true, bool $round = false, bool $result = false, bool $rematch = false): array
{
    return [
        'id', 'status', 'end_reason', 'mode', 'role',
        'course' => BATTLE_CONTRACT_COURSE,
        ...($lesson ? ['lesson' => BATTLE_CONTRACT_LESSON] : ['lesson']),
        'opponent' => ['first_name'],
        'rounds_total', 'round_seconds', 'countdown_seconds', 'reveal_seconds', 'presence_grace_seconds', 'presence_warn_seconds', 'deadline_grace_ms',
        'invite_expires_at', 'room_expires_at', 'starts_at', 'version', 'server_now',
        'ready' => ['me', 'opponent'], 'opponent_present', 'opponent_absent_since',
        ...($round ? ['round' => BATTLE_CONTRACT_ROUND_STATE] : ['round']),
        'scores' => ['me', 'opponent'],
        ...($result ? ['result' => ['outcome', 'end_reason', 'rounds' => ['*' => ['no', 'me' => ['verdict', 'points'], 'opponent' => ['verdict', 'points']]]]] : ['result']),
        ...($rematch ? ['rematch' => BATTLE_CONTRACT_REMATCH] : ['rematch']),
        'friendship_id', 'audio_preload',
    ];
}

/** Every key of a decoded JSON at any depth. @return list<string> */
function battleKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...battleKeys($item)];
    }

    return $keys;
}

/** Both players poll: the presence is written, whatever is due happens. */
function battlePollBoth(int $id, Learner $a, Learner $b): void
{
    foreach ([$a, $b] as $player) {
        BattleHttp::poll($player, $id)->assertOk();
    }
}

/**
 * Time passes by `$seconds` (both players poll all along: they are here), then the phase that was due is over.
 */
function battleWait(int $id, Learner $a, Learner $b, float $seconds): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds((int) ($seconds * 1000)));
    battlePollBoth($id, $a, $b);
}

// ------------------------------------------------------------------------------------------------------------ the battle, status by status

it('keeps the exact JSON of a battle from the invitation to the countdown, for both players, and lists the clips of a dictation in the room', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // The invitation: 201 with the whole body in the language of the one who invited.
    $created = BattleHttp::invite($me, $friendship, PracticeMode::Dictation, $course, $lesson)->assertCreated()->assertExactJsonStructure(battleShape());
    $id = (int) $created->json('id');
    expect(ksorted($created->json()))->toBe(ksorted([
        'id' => $id, 'status' => 'invited', 'end_reason' => null, 'mode' => 'dictation', 'role' => 'challenger',
        'course' => ['slug' => 'english-a1', 'title' => 'Английский A1'],
        'lesson' => ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'],
        'opponent' => ['first_name' => 'Dilnoza'],
        'rounds_total' => 5, 'round_seconds' => 60, 'countdown_seconds' => 3, 'reveal_seconds' => 4,
        'presence_grace_seconds' => 30, 'presence_warn_seconds' => 10, 'deadline_grace_ms' => 1500,
        'invite_expires_at' => '2026-10-30T10:05:00.000Z', 'room_expires_at' => null, 'starts_at' => null,
        'version' => 0, 'server_now' => '2026-10-30T10:00:00.000Z',
        'ready' => ['me' => false, 'opponent' => false], 'opponent_present' => true, 'opponent_absent_since' => null,
        'round' => null, 'scores' => ['me' => 0, 'opponent' => 0], 'result' => null,
        'rematch' => null, 'friendship_id' => $friendship->id, 'audio_preload' => null,
    ]));

    // The same battle for the friend: their side, their language; a read is the same body as the 201 (except for the side).
    $theirs = BattleHttp::view($friend, $id)->assertOk()->assertExactJsonStructure(battleShape());
    expect([$theirs->json('role'), $theirs->json('opponent'), $theirs->json('course.title')])->toBe(['opponent', ['first_name' => 'Bek'], 'Ingliz tili A1']);
    $mine = BattleHttp::view($me, $id)->assertOk();
    expect(ksorted($mine->json()))->toBe(ksorted($created->json()));

    // The room: both are in, the clocks of the room run, and the five clips of the dictation are listed for the preload.
    BattleHttp::accept($friend, $id)->assertOk()->assertExactJsonStructure(battleShape());
    $room = BattleHttp::view($me, $id)->assertOk()->assertExactJsonStructure(battleShape());
    expect([$room->json('status'), $room->json('version'), $room->json('room_expires_at'), $room->json('ready'), $room->json('round')])
        ->toBe(['room', 1, '2026-10-30T10:02:00.000Z', ['me' => false, 'opponent' => false], null])
        ->and($room->json('audio_preload'))->toHaveCount(5);
    foreach ($room->json('audio_preload') as $url) {
        expect($url)->toMatch("#^/api/twa/practice/battles/{$id}/audio/e[A-Za-z0-9]{10}$#");
    }

    // One ready: the flag is mine only; the other side sees it as the opponent's.
    BattleHttp::ready($me, $id)->assertOk()->assertJsonPath('ready', ['me' => true, 'opponent' => false]);
    BattleHttp::view($friend, $id)->assertOk()->assertJsonPath('ready', ['me' => false, 'opponent' => true]);

    // The countdown: round 1 has its number and its deadline, no phase; the clips are still listed.
    $countdown = BattleHttp::ready($friend, $id)->assertOk()->assertExactJsonStructure(battleShape(round: true));
    expect($countdown->json('status'))->toBe('countdown')
        ->and($countdown->json('starts_at'))->toBe('2026-10-30T10:00:03.000Z')
        ->and($countdown->json('round'))->toBe(['no' => 1, 'phase' => null, 'deadline_at' => '2026-10-30T10:01:03.000Z', 'phase_ends_at' => null, 'my_answered' => false, 'opponent_answered' => false])
        ->and($countdown->json('audio_preload'))->toBe($room->json('audio_preload'))
        ->and($countdown->json('result'))->toBeNull();
});

it('keeps the exact JSON of a battle in play and at its end, and gives the audio_preload of the room string for string as the audio_url of the rounds', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $preload = BattleHttp::view($me, $id)->json('audio_preload');
    BattleHttp::ready($me, $id)->assertOk();
    BattleHttp::ready($friend, $id)->assertOk();

    // The countdown has round 1 to read already (no phase yet): its clip is the first of the list.
    expect(BattleHttp::round($me, $id, 1)->assertOk()->json('element.audio_url'))->toBe($preload[0]);
    CarbonImmutable::setTestNow(BattleKit::fresh($id)->starts_at);
    BattleHttp::poll($me, $id)->assertOk()->assertJsonPath('status', 'playing');

    // Round 1 is answering: the phase, the deadline; nobody has answered; the clips are not listed any more.
    $playing = BattleHttp::view($me, $id)->assertOk()->assertExactJsonStructure(battleShape(round: true));
    expect($playing->json('status'))->toBe('playing')
        ->and($playing->json('round'))->toBe(['no' => 1, 'phase' => 'answering', 'deadline_at' => '2026-10-30T10:01:03.000Z', 'phase_ends_at' => null, 'my_answered' => false, 'opponent_answered' => false])
        ->and($playing->json('audio_preload'))->toBeNull();

    // Five rounds. I answer right, the friend right in the even rounds and wrong in the odd ones.
    $urls = [];
    for ($round = 1; $round <= 5; $round++) {
        $element = BattleKit::element(BattleKit::fresh($id), $round);
        $urls[] = BattleHttp::round($me, $id, $round)->assertOk()->json('element.audio_url');
        $mine = BattleHttp::answer($me, $id, $round, BattleKit::correct($element))->assertOk();
        $theirs = BattleHttp::answer($friend, $id, $round, $round % 2 === 0 ? BattleKit::correct($element) : BattleKit::wrong($element))->assertOk();
        expect($mine->json('round_resolved'))->toBeFalse()
            ->and($theirs->json('round_resolved'))->toBeTrue();

        // Reveal: the phase and when it ends; both have answered.
        $reveal = BattleHttp::view($me, $id)->assertOk()->assertExactJsonStructure(battleShape(round: true));
        expect($reveal->json('round'))->toMatchArray(['no' => $round, 'phase' => 'reveal', 'my_answered' => true, 'opponent_answered' => true])
            ->and($reveal->json('round.phase_ends_at'))->toBe(CarbonImmutable::now()->addSeconds(4)->format('Y-m-d\TH:i:s.v\Z'));
        battleWait($id, $me, $friend, 4);
    }

    // The preload of the room is, in the same order and byte for byte, the audio_url of the five rounds (the blob is found by the URL).
    expect($preload)->toHaveCount(5)
        ->and($urls)->toBe($preload);
    foreach ($preload as $url) {
        expect($url)->toStartWith("/api/twa/practice/battles/{$id}/audio/");
    }

    // The end: the result, from either side, mirrored; no round, no clips; the scores are the sums of the rounds.
    $mineOver = BattleHttp::view($me, $id)->assertOk()->assertExactJsonStructure(battleShape(result: true));
    $theirsOver = BattleHttp::view($friend, $id)->assertOk()->assertExactJsonStructure(battleShape(result: true));
    expect([$mineOver->json('status'), $mineOver->json('end_reason'), $mineOver->json('round'), $mineOver->json('audio_preload')])->toBe(['finished', 'completed', null, null])
        ->and($mineOver->json('scores'))->toBe(['me' => 500, 'opponent' => 200])
        ->and($theirsOver->json('scores'))->toBe(['me' => 200, 'opponent' => 500])
        ->and($mineOver->json('result.outcome'))->toBe('won')
        ->and($theirsOver->json('result.outcome'))->toBe('lost')
        ->and($mineOver->json('result.end_reason'))->toBe('completed')
        ->and($mineOver->json('result.rounds'))->toHaveCount(5)
        ->and($mineOver->json('result.rounds.0'))->toBe(['no' => 1, 'me' => ['verdict' => 'correct', 'points' => 100], 'opponent' => ['verdict' => 'wrong', 'points' => 0]])
        ->and($theirsOver->json('result.rounds.0'))->toBe(['no' => 1, 'me' => ['verdict' => 'wrong', 'points' => 0], 'opponent' => ['verdict' => 'correct', 'points' => 100]])
        ->and($mineOver->json('result.rounds.1'))->toBe(['no' => 2, 'me' => ['verdict' => 'correct', 'points' => 100], 'opponent' => ['verdict' => 'correct', 'points' => 100]]);
});

it('gives a battle that was closed a result of its own - `outcome: none`, the reason, the rounds that were resolved - for every way it can be closed, both_absent included', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    $declined = (int) BattleHttp::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson)->json('id');
    BattleHttp::decline($friend, $declined)->assertOk();

    $withdrawn = (int) BattleHttp::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson)->json('id');
    BattleHttp::cancel($me, $withdrawn)->assertOk();

    $expired = (int) BattleHttp::invite($me, $friendship, PracticeMode::Pairs, $course, $lesson)->json('id');
    CarbonImmutable::setTestNow('2026-10-30 10:05:00');
    BattleHttp::view($me, $expired)->assertOk();   // the first look closes it
    CarbonImmutable::setTestNow(BattleKit::T0);

    $left = BattleHttp::room($me, $friend, $friendship, PracticeMode::Pairs, $course, $lesson);
    BattleHttp::cancel($friend, $left)->assertOk();

    $roomExpired = BattleHttp::room($me, $friend, $friendship, PracticeMode::Pairs, $course, $lesson);
    CarbonImmutable::setTestNow('2026-10-30 10:02:00');
    battlePollBoth($roomExpired, $me, $friend);
    CarbonImmutable::setTestNow(BattleKit::T0);

    $voided = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    Storage::disk('practice_audio')->delete(BattleKit::element(BattleKit::fresh($voided), 3)['audio_path']);
    BattleHttp::ready($me, $voided)->assertOk()->assertJsonPath('status', 'closed');

    // Both left in the middle of the game, after one round was played: nobody has polled for 40 s, the sweeper closes it.
    $both = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Sentence, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($both), 1);
    BattleHttp::answer($me, $both, 1, BattleKit::correct($element))->assertOk();
    BattleHttp::answer($friend, $both, 1, BattleKit::wrong($element))->assertOk();
    CarbonImmutable::setTestNow('2026-10-30 10:00:40');
    expect(app(BattleJanitor::class)->sweep())->toBe(1);

    foreach ([
        ['declined', $declined, 0], ['canceled', $withdrawn, 0], ['invite_expired', $expired, 0], ['canceled', $left, 0],
        ['room_expired', $roomExpired, 0], ['voided', $voided, 0], ['both_absent', $both, 1],
    ] as [$reason, $id, $played]) {
        foreach ([$me, $friend] as $viewer) {
            $view = BattleHttp::view($viewer, $id)->assertOk()->assertExactJsonStructure(battleShape(result: true));

            expect($view->json('status'))->toBe('closed', $reason)
                ->and($view->json('end_reason'))->toBe($reason)
                ->and($view->json('result.outcome'))->toBe('none', $reason)
                ->and($view->json('result.end_reason'))->toBe($reason)
                ->and($view->json('result.rounds'))->toHaveCount($played, $reason)
                ->and($view->json('round'))->toBeNull()
                ->and($view->json('opponent_present'))->toBeTrue()
                ->and($view->json('opponent_absent_since'))->toBeNull();
        }
        // The poll of it says the same in a few words.
        BattleHttp::poll($me, $id)->assertOk()->assertExactJsonStructure(BATTLE_CONTRACT_POLL_KEYS)->assertJsonPath('status', 'closed')->assertJsonPath('end_reason', $reason);
    }

    // The one round that was played before both left is in the result, with what each side answered; its points are counted.
    $view = BattleHttp::view($me, $both)->assertOk();
    expect($view->json('result.rounds.0'))->toBe(['no' => 1, 'me' => ['verdict' => 'correct', 'points' => 100], 'opponent' => ['verdict' => 'wrong', 'points' => 0]])
        ->and($view->json('scores'))->toBe(['me' => 100, 'opponent' => 0]);
});
