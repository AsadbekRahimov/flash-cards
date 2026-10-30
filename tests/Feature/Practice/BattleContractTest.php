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
use App\Domain\Practice\Support\IsoTime;
use App\Models\Battle;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

uses(RefreshDatabase::class);

const BATTLE_CONTRACT_COURSE = ['slug', 'title'];
const BATTLE_CONTRACT_LESSON = ['id', 'stage', 'number', 'title'];
const BATTLE_CONTRACT_ROUND_STATE = ['no', 'phase', 'deadline_at', 'phase_ends_at', 'my_answered', 'opponent_answered'];
const BATTLE_CONTRACT_REMATCH = ['battle_id', 'status', 'by_me'];
const BATTLE_CONTRACT_SIDE = ['answer', 'verdict', 'points'];

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

/**
 * The structure of the poll (and of the `poll` of an answer): the same keys for every status.
 *
 * @return array<int|string, mixed>
 */
function battlePollShape(bool $round = false, bool $rematch = false): array
{
    return [
        'id', 'status', 'version', 'server_now', 'ready' => ['me', 'opponent'], 'opponent_present', 'opponent_absent_since',
        ...($round ? ['round' => BATTLE_CONTRACT_ROUND_STATE] : ['round']),
        'scores' => ['me', 'opponent'], 'end_reason',
        ...($rematch ? ['rematch' => BATTLE_CONTRACT_REMATCH] : ['rematch']),
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
        PracticeKit::resetLimiters($player);   // the clock stands still between the jumps: the limits of a minute are BattleRateLimitTest's
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
        BattleHttp::poll($me, $id)->assertOk()->assertExactJsonStructure(battlePollShape())->assertJsonPath('status', 'closed')->assertJsonPath('end_reason', $reason);
    }

    // The one round that was played before both left is in the result, with what each side answered; its points are counted.
    $view = BattleHttp::view($me, $both)->assertOk();
    expect($view->json('result.rounds.0'))->toBe(['no' => 1, 'me' => ['verdict' => 'correct', 'points' => 100], 'opponent' => ['verdict' => 'wrong', 'points' => 0]])
        ->and($view->json('scores'))->toBe(['me' => 100, 'opponent' => 0]);
});

// ------------------------------------------------------------------------------------------------------------ a round: the element and the review

/**
 * The element of a round as the TWA draws it, by the mode (docs/16 §7.2: the same forms as the solo `current`).
 *
 * @return array<int|string, mixed>
 */
function battleElementShape(string $mode): array
{
    return match ($mode) {
        'pairs' => ['id', 'kind', 'round', 'rounds', 'left' => ['*' => ['id', 'text']], 'right' => ['*' => ['id', 'text']], 'matched', 'mismatches'],
        'collocation' => ['id', 'kind', 'text', 'options' => ['*' => ['id', 'text']]],
        'sentence' => ['id', 'kind', 'prompt', 'fragments' => ['*' => ['id', 'text']]],
        default => ['id', 'kind', 'source', 'audio_url', 'words', 'replacements_left'],
    };
}

/**
 * The review of a resolved round: the right answer in the form of the mode, what each player gave, the explanation.
 *
 * @return array<int|string, mixed>
 */
function battleRevealShape(string $mode): array
{
    return [
        'correct' => match ($mode) {
            'pairs' => ['pairs'],
            'collocation' => ['correct_option_ids', 'full_text'],
            'sentence' => ['correct_text', 'alternatives'],
            default => ['expected'],
        },
        'me' => BATTLE_CONTRACT_SIDE,
        'opponent' => BATTLE_CONTRACT_SIDE,
        'explanation', 'translation',
    ];
}

/** @return array<int|string, mixed> */
function battleRoundShape(string $mode, bool $reveal = false): array
{
    return ['no', 'phase', 'deadline_at', 'phase_ends_at', 'server_now', 'element' => battleElementShape($mode), ...($reveal ? ['reveal' => battleRevealShape($mode)] : ['reveal'])];
}

/**
 * What no client may read before a round is reviewed: the texts of the right answers, of the explanations and the translations,
 * the path of a clip - of ALL five rounds of the snapshot, the current one included.
 *
 * @return list<string>
 */
function battleSecrets(int $battleId): array
{
    $secrets = [];
    foreach (BattleKit::fresh($battleId)->snapshot['elements'] as $element) {
        foreach (['full_text', 'expected', 'display', 'audio_path'] as $key) {
            if (isset($element[$key]) && is_string($element[$key])) {
                $secrets[] = $element[$key];
            }
        }
        foreach ([...(array) ($element['accepted_display'] ?? []), ...(array) ($element['translation'] ?? []), ...(array) ($element['explanation'] ?? [])] as $text) {
            if (is_string($text) && $text !== '') {
                $secrets[] = $text;
            }
        }
    }

    return array_values(array_unique($secrets));
}

/** A response as one string, for "this text is nowhere in it". */
function battleBody(TestResponse $response): string
{
    return json_encode($response->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Asserts that nothing of the snapshot is in the responses: no key that lives there, none of the secret texts.
 *
 * @param  list<TestResponse>  $responses
 */
function battleAssertNoSecrets(array $responses, int $battleId): void
{
    $secrets = battleSecrets($battleId);
    foreach ($responses as $response) {
        expect(array_values(array_intersect(battleKeys($response->json()), BATTLE_CONTRACT_FORBIDDEN_KEYS)))->toBe([]);
        foreach ($secrets as $secret) {
            expect(battleBody($response))->not->toContain($secret);
        }
    }
}

/** The error of a request as it is on the wire: `{"error": {"code", "message"[, "details"]}}` and nothing else at the top. @param list<string>|null $keys the keys of `details` (null: none) @return array<string, mixed> `error.details` */
function battleError(TestResponse $response, int $status, string $code, ?array $keys): array
{
    $response->assertStatus($status);
    $body = $response->json();

    expect(array_keys($body))->toBe(['error'])
        ->and($body['error']['code'])->toBe($code)
        ->and($body['error']['message'])->toBeString()->not->toBe('')
        ->and(array_keys($body['error']))->toBe($keys === null ? ['code', 'message'] : ['code', 'message', 'details']);

    if ($keys === null) {
        return [];
    }

    expect(array_keys($body['error']['details']))->toEqualCanonicalizing($keys);

    return $body['error']['details'];
}

/** The clock goes to the end of the current round, 2 s past the deadline and its grace, in steps the players keep up with by polling. */
function battleRunOut(int $id, Learner $a, Learner $b): void
{
    $deadline = BattleKit::fresh($id)->round_deadline_at ?? throw new RuntimeException('The round has no deadline.');
    $until = $deadline->addSeconds(2);
    while (CarbonImmutable::now()->lt($until)) {
        $step = min(15.0, (float) CarbonImmutable::now()->diffInSeconds($until, true));
        battleWait($id, $a, $b, max($step, 0.5));
    }
}

dataset('battle modes', ['pairs', 'collocation', 'sentence', 'dictation']);

it('reads a round of every mode in the language of the reader, with none of the secrets of the snapshot, no review while it is open - and the opponent\'s answer not on the device', function (string $mode): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::from($mode), $course, $lesson);
    $row = BattleKit::fresh($id);
    $element = BattleKit::element($row, 1);

    // The round for each player: the exact shape, the clocks of the round that is on, no review.
    $mine = BattleHttp::round($me, $id, 1)->assertOk()->assertExactJsonStructure(battleRoundShape($mode));
    $theirs = BattleHttp::round($friend, $id, 1)->assertOk()->assertExactJsonStructure(battleRoundShape($mode));
    expect($mine->json())->toMatchArray(['no' => 1, 'phase' => 'answering', 'phase_ends_at' => null, 'reveal' => null, 'server_now' => '2026-10-30T10:00:03.000Z'])
        ->and($mine->json('deadline_at'))->toBe(IsoTime::format($row->round_deadline_at ?? throw new RuntimeException('no deadline')))
        ->and($mine->json('element.id'))->toBe($element['id'])
        ->and($theirs->json('element.id'))->toBe($element['id']);

    // The words of each reader are those of their side of the snapshot; what has no words is the same for both.
    if ($mode === 'sentence') {
        expect($mine->json('element.prompt'))->toBe($element['prompt']['ru'])
            ->and($theirs->json('element.prompt'))->toBe($element['prompt']['uz']);
    } elseif ($mode === 'pairs') {
        expect($mine->json('element.round'))->toBe(1)
            ->and($mine->json('element.rounds'))->toBe(5)
            ->and($mine->json('element.matched'))->toBe([])
            ->and($mine->json('element.mismatches'))->toBe(0)
            ->and($mine->json('element.left'))->toHaveCount(4)
            ->and($mine->json('element.left'))->toBe($theirs->json('element.left'))                       // the English side is one for both
            ->and(array_column($mine->json('element.right'), 'id'))->toBe(array_column($theirs->json('element.right'), 'id'));
        foreach ($mine->json('element.right') as $card) {
            expect($card['text'])->toStartWith('перевод ');
        }
        foreach ($theirs->json('element.right') as $card) {
            expect($card['text'])->toStartWith('tarjima ');
        }
        // The ids say nothing: a prefix and ten characters, the order of the cards is not the order of the pairs.
        expect($mine->json('element.id'))->toMatch('/^e[A-Za-z0-9]{10}$/');
        foreach (array_column($mine->json('element.left'), 'id') as $leftId) {
            expect($leftId)->toMatch('/^l[A-Za-z0-9]{10}$/');
        }
        foreach (array_column($mine->json('element.right'), 'id') as $rightId) {
            expect($rightId)->toMatch('/^r[A-Za-z0-9]{10}$/');
        }
    } else {
        expect($theirs->json('element'))->toBe($mine->json('element'));
    }
    if ($mode === 'dictation') {
        expect($mine->json('element.audio_url'))->toBe("/api/twa/practice/battles/{$id}/audio/{$element['id']}")
            ->and($mine->json('element.replacements_left'))->toBe(0);   // a battle never swaps a clip
    }

    // Nothing of the snapshot in anything the players can read now (the pairs have no text of the answer to hide: only the table, a key).
    expect(battleSecrets($id) !== [] || $mode === 'pairs')->toBeTrue();
    battleAssertNoSecrets([
        $mine, $theirs, BattleHttp::view($me, $id), BattleHttp::view($friend, $id), BattleHttp::poll($me, $id), BattleHttp::poll($friend, $id),
        BattleHttp::get($me, ''), BattleHttp::options($me, $friendship, $course, $lesson),
    ], $id);

    // A round that has not begun is not there (409, in the shape of the refusals); neither is nonsense.
    expect(battleError(BattleHttp::round($me, $id, 2), 409, 'round_not_open', ['round', 'phase']))->toBe(['round' => 2, 'phase' => 'answering']);
    expect(battleError(BattleHttp::round($me, $id, 6), 409, 'round_not_open', ['round', 'phase']))->toBe(['round' => 6, 'phase' => 'answering']);
    BattleHttp::get($me, "/{$id}/round?no=0")->assertStatus(422);
    BattleHttp::get($me, "/{$id}/round?no=x")->assertStatus(422);
    BattleHttp::get($me, "/{$id}/round")->assertStatus(422);

    // I answer. The friend is told that I have, and nothing more: no verdict, no points, no score, no review.
    PracticeKit::resetLimiters($friend);
    $answer = BattleHttp::answer($me, $id, 1, BattleKit::correct($element))->assertOk()->assertExactJsonStructure(['verdict', 'points', 'accepted_at', 'round_resolved', 'poll' => battlePollShape(round: true)]);
    expect($answer->json('verdict'))->toBe('correct')
        ->and($answer->json('points'))->toBe(100)
        ->and($answer->json('accepted_at'))->toBe('2026-10-30T10:00:03.000Z')
        ->and($answer->json('round_resolved'))->toBeFalse()
        ->and($answer->json('poll.round'))->toMatchArray(['no' => 1, 'phase' => 'answering', 'my_answered' => true, 'opponent_answered' => false])
        ->and($answer->json('poll.scores'))->toBe(['me' => 0, 'opponent' => 0]);   // my own score does not move before the round is resolved either

    $friendPoll = BattleHttp::poll($friend, $id)->assertOk()->assertExactJsonStructure(battlePollShape(round: true));
    $friendView = BattleHttp::view($friend, $id)->assertOk();
    $friendRound = BattleHttp::round($friend, $id, 1)->assertOk()->assertExactJsonStructure(battleRoundShape($mode));
    expect($friendPoll->json('round'))->toMatchArray(['my_answered' => false, 'opponent_answered' => true])
        ->and($friendPoll->json('scores'))->toBe(['me' => 0, 'opponent' => 0])
        ->and($friendView->json('scores'))->toBe(['me' => 0, 'opponent' => 0])
        ->and($friendView->json('result'))->toBeNull()
        ->and($friendRound->json('reveal'))->toBeNull();
    foreach ([$friendPoll, $friendView, $friendRound] as $response) {
        expect(array_values(array_intersect(battleKeys($response->json()), ['verdict', 'points', 'answer'])))->toBe([]);
    }
    battleAssertNoSecrets([$friendPoll, $friendView, $friendRound], $id);
})->with('battle modes');

it('reviews a resolved round in the form of its mode - the right answer, what each player gave, the explanation in the language of the reader - and goes on reviewing it, a time-out included', function (string $mode): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::from($mode), $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    $right = BattleKit::correct($element);
    $wrong = BattleKit::wrong($element);

    // One answer is in: no review yet, for either.
    BattleHttp::answer($me, $id, 1, $right)->assertOk();
    foreach ([$me, $friend] as $who) {
        BattleHttp::round($who, $id, 1)->assertOk()->assertJsonPath('reveal', null)->assertJsonPath('phase', 'answering');
    }

    // The second answer resolves the round: the phase is the review, the scores have moved.
    $second = BattleHttp::answer($friend, $id, 1, $wrong)->assertOk();
    $points = $mode === 'pairs' ? 50 : 0;   // two of four pairs are right in the wrong answer of the kit
    expect($second->json('round_resolved'))->toBeTrue()
        ->and($second->json('verdict'))->toBe('wrong')
        ->and($second->json('points'))->toBe($points)
        ->and($second->json('poll.scores'))->toBe(['me' => $points, 'opponent' => 100])
        ->and($second->json('poll.round'))->toMatchArray(['no' => 1, 'phase' => 'reveal']);

    $shape = battleRoundShape($mode, reveal: true);
    $mineRound = BattleHttp::round($me, $id, 1)->assertOk()->assertExactJsonStructure($shape);
    $theirsRound = BattleHttp::round($friend, $id, 1)->assertOk()->assertExactJsonStructure($shape);
    expect($mineRound->json('phase'))->toBe('reveal')
        ->and($mineRound->json('phase_ends_at'))->toBe('2026-10-30T10:00:07.000Z')
        ->and($mineRound->json('deadline_at'))->not->toBeNull();

    // The right answer, in the form of the mode, the same for both readers.
    $correct = match ($mode) {
        'pairs' => ['pairs' => $element['pairs']],
        'collocation' => ['correct_option_ids' => $element['correct_ids'], 'full_text' => $element['full_text']],
        'sentence' => ['correct_text' => $element['accepted_display'][0], 'alternatives' => array_slice($element['accepted_display'], 1)],
        default => ['expected' => $element['display']],
    };
    expect(ksorted($mineRound->json('reveal.correct')))->toBe(ksorted($correct))
        ->and(ksorted($theirsRound->json('reveal.correct')))->toBe(ksorted($correct));

    // What each player gave: the answer as it was taken (the form the client sent it in), the verdict, the points - from either side.
    expect($mineRound->json('reveal.me'))->toBe(['answer' => $mineRound->json('reveal.me.answer'), 'verdict' => 'correct', 'points' => 100])
        ->and($mineRound->json('reveal.opponent'))->toBe(['answer' => $mineRound->json('reveal.opponent.answer'), 'verdict' => 'wrong', 'points' => $points])
        ->and($theirsRound->json('reveal.me.verdict'))->toBe('wrong')
        ->and($theirsRound->json('reveal.opponent.verdict'))->toBe('correct')
        ->and($theirsRound->json('reveal.me.answer'))->toBe($mineRound->json('reveal.opponent.answer'))
        ->and($theirsRound->json('reveal.opponent.answer'))->toBe($mineRound->json('reveal.me.answer'));
    match ($mode) {
        'pairs' => expect(ksorted($mineRound->json('reveal.me.answer')))->toBe(ksorted(['pairs' => $right['pairs']]))
            ->and(array_keys($mineRound->json('reveal.opponent.answer')))->toBe(['pairs'])
            ->and($mineRound->json('reveal.opponent.answer.pairs'))->not->toBe($right['pairs']),
        'collocation' => expect($mineRound->json('reveal.me.answer'))->toBe($right)
            ->and($mineRound->json('reveal.opponent.answer'))->toBe($wrong),
        'sentence' => expect($mineRound->json('reveal.me.answer'))->toBe($right)
            ->and($mineRound->json('reveal.opponent.answer'))->toBe($wrong),
        default => expect($mineRound->json('reveal.me.answer'))->toBe($right)
            ->and($mineRound->json('reveal.opponent.answer'))->toBe($wrong),
    };

    // The explanation and the translation are in the language of the reader (ru / uz), or null where the mode has none.
    foreach ([[$mineRound, 'ru'], [$theirsRound, 'uz']] as [$round, $locale]) {
        $translation = match ($mode) {
            'sentence' => $element['prompt'][$locale],
            'collocation', 'dictation' => $element['translation'][$locale],
            default => null,
        };
        $explanation = match ($mode) {
            'sentence', 'collocation' => $element['explanation'][$locale],
            default => null,
        };
        expect($round->json('reveal.translation'))->toBe($translation, "{$mode} translation ({$locale})")
            ->and($round->json('reveal.explanation'))->toBe($explanation, "{$mode} explanation ({$locale})");
    }

    // Round 2 begins; nobody answers; the deadline and its grace pass with both players here: a time-out for both, reviewed like the rest.
    battleWait($id, $me, $friend, 4);
    expect(BattleHttp::view($me, $id)->json('round'))->toMatchArray(['no' => 2, 'phase' => 'answering']);
    battleRunOut($id, $me, $friend);
    $timeout = BattleHttp::round($me, $id, 2)->assertOk()->assertExactJsonStructure($shape);
    expect($timeout->json('phase'))->toBe('reveal')
        ->and($timeout->json('reveal.me'))->toBe(['answer' => null, 'verdict' => 'timeout', 'points' => 0])
        ->and($timeout->json('reveal.opponent'))->toBe(['answer' => null, 'verdict' => 'timeout', 'points' => 0])
        ->and(BattleHttp::view($me, $id)->json('scores'))->toBe(['me' => 100, 'opponent' => $points]);   // a time-out adds nothing

    // The next round: the review of the ones behind stays - phase `reveal`, no clocks, the same body; the one that is on has none yet.
    battleWait($id, $me, $friend, 4);
    expect(BattleHttp::view($me, $id)->json('round'))->toMatchArray(['no' => 3, 'phase' => 'answering']);
    $again = BattleHttp::round($me, $id, 1)->assertOk()->assertExactJsonStructure($shape);
    expect($again->json('phase'))->toBe('reveal')
        ->and($again->json('deadline_at'))->toBeNull()
        ->and($again->json('phase_ends_at'))->toBeNull()
        ->and($again->json('reveal'))->toBe($mineRound->json('reveal'))
        ->and(BattleHttp::round($me, $id, 2)->assertOk()->json('reveal.me.verdict'))->toBe('timeout');
    $current = BattleHttp::round($me, $id, 3)->assertOk()->assertExactJsonStructure(battleRoundShape($mode));
    expect($current->json('phase'))->toBe('answering')
        ->and($current->json('reveal'))->toBeNull();
    battleError(BattleHttp::round($me, $id, 4), 409, 'round_not_open', ['round', 'phase']);
})->with('battle modes');
