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
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

// ------------------------------------------------------------------------------------------------------------ the options, the list, the poll

it('keeps the exact JSON of the options of a battle - both players\' languages, the state of every format - and refuses what docs/16 §7.3 refuses', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    config(['practice.modes.sentence' => false]);
    $row = ['mode', 'state', 'available', 'required', 'reason', 'round_seconds'];

    $options = BattleHttp::options($me, $friendship, $course, $lesson)->assertOk()->assertExactJsonStructure([
        'friend' => ['first_name'], 'course' => BATTLE_CONTRACT_COURSE, 'lesson' => BATTLE_CONTRACT_LESSON, 'modes' => ['*' => $row], 'friend_access',
    ]);
    $modes = $options->json('modes');
    expect($options->json('friend'))->toBe(['first_name' => 'Dilnoza'])
        ->and($options->json('course'))->toBe(['slug' => 'english-a1', 'title' => 'Английский A1'])
        ->and(array_column($modes, 'mode'))->toBe(['pairs', 'collocation', 'sentence', 'dictation'])
        ->and(array_column($modes, 'state'))->toBe(['available', 'available', 'disabled', 'available'])
        ->and(array_column($modes, 'round_seconds'))->toBe([45, 30, 45, 60])
        ->and($modes[0])->toBe(['mode' => 'pairs', 'state' => 'available', 'available' => 24, 'required' => 20, 'reason' => null, 'round_seconds' => 45])
        ->and($options->json('friend_access'))->toBe('available');

    // The whole course (no lesson) and the other side: the friend is Bek, the course is named in their language.
    $theirs = BattleHttp::options($friend, $friendship, $course)->assertOk()->assertExactJsonStructure([
        'friend' => ['first_name'], 'course' => BATTLE_CONTRACT_COURSE, 'lesson', 'modes' => ['*' => $row], 'friend_access',
    ]);
    expect([$theirs->json('friend'), $theirs->json('course.title'), $theirs->json('lesson')])->toBe([['first_name' => 'Bek'], 'Ingliz tili A1', null]);

    // A friend who cannot practice (the pilot is not open to them) is told, not refused: the setup screen explains it.
    config(['practice.access' => 'pilot']);
    $me->update(['practice_pilot_at' => now()]);
    BattleHttp::options($me, $friendship, $course)->assertOk()->assertJsonPath('friend_access', 'no_access');
    config(['practice.access' => 'all']);

    // The refusals, in the envelope: a pair that is not mine, one that is not accepted, a deck, a lesson that is not there / not of the course.
    $stranger = Learner::factory()->create();
    $pending = Friendship::factory()->between($me, Learner::factory()->create())->pending()->create();
    $private = Course::factory()->published()->create(['slug' => 'private-deck', 'visibility' => 'private']);
    [, $foreign] = PracticeKit::course('other-course');
    battleError(BattleHttp::options($stranger, $friendship, $course), 404, 'friendship_not_found', null);
    battleError(BattleHttp::options($me, $pending, $course), 403, 'not_friends', null);
    battleError(BattleHttp::options($me, $friendship, $private), 404, 'course_not_found', null);
    battleError(BattleHttp::get($me, "/options?friendship_id={$friendship->id}&course={$course->slug}&lesson_id=999999"), 404, 'lesson_not_found', null);
    battleError(BattleHttp::get($me, "/options?friendship_id={$friendship->id}&course={$course->slug}&lesson_id={$foreign->id}"), 422, 'lesson_not_in_course', null);
    BattleHttp::get($me, '/options')->assertStatus(422)->assertJsonValidationErrors(['friendship_id', 'course']);
    config(['practice.battle.enabled' => false]);
    battleError(BattleHttp::options($me, $friendship, $course), 409, 'battle_disabled', null);
});

it('lists the battles with the exact JSON - the one in hand (compact: the seven keys the TWA reads), the invitations to the learner, the last ones that are over', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $compact = ['id', 'status', 'mode', 'role', 'course' => BATTLE_CONTRACT_COURSE, 'lesson' => BATTLE_CONTRACT_LESSON, 'opponent' => ['first_name']];
    $incoming = ['id', 'mode', 'from' => ['first_name'], 'course' => BATTLE_CONTRACT_COURSE, 'lesson' => BATTLE_CONTRACT_LESSON, 'invite_expires_at', 'rematch'];
    $finished = ['id', 'mode', 'opponent' => ['first_name'], 'outcome', 'scores' => ['me', 'opponent'], 'finished_at'];

    // Nothing yet.
    expect(BattleHttp::get($me, '')->assertOk()->json())->toBe(['active' => null, 'incoming' => [], 'finished' => []]);

    // An invitation: for me the match in hand, for the friend a row of "incoming".
    $id = (int) BattleHttp::invite($me, $friendship, PracticeMode::Sentence, $course, $lesson)->json('id');
    $mine = BattleHttp::get($me, '')->assertOk()->assertExactJsonStructure(['active' => $compact, 'incoming', 'finished']);
    $theirs = BattleHttp::get($friend, '')->assertOk()->assertExactJsonStructure(['active', 'incoming' => ['*' => $incoming], 'finished']);
    expect($mine->json('active'))->toBe([
        'id' => $id, 'status' => 'invited', 'mode' => 'sentence', 'role' => 'challenger',
        'course' => ['slug' => 'english-a1', 'title' => 'Английский A1'], 'lesson' => ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'],
        'opponent' => ['first_name' => 'Dilnoza'],
    ])
        ->and($theirs->json('active'))->toBeNull()
        ->and($theirs->json('incoming'))->toBe([[
            'id' => $id, 'mode' => 'sentence', 'from' => ['first_name' => 'Bek'],
            'course' => ['slug' => 'english-a1', 'title' => 'Ingliz tili A1'], 'lesson' => ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'],
            'invite_expires_at' => '2026-10-30T10:05:00.000Z', 'rematch' => false,
        ]]);

    // Accepted, then in the game: still the same seven keys for the match in hand (the round is the battle's, not the list's).
    BattleHttp::accept($friend, $id)->assertOk();
    foreach ([$me, $friend] as $who) {
        $room = BattleHttp::get($who, '')->assertOk()->assertExactJsonStructure(['active' => $compact, 'incoming', 'finished']);
        expect($room->json('active.status'))->toBe('room')
            ->and($room->json('incoming'))->toBe([]);
    }
    BattleHttp::ready($me, $id)->assertOk();
    BattleHttp::ready($friend, $id)->assertOk();
    CarbonImmutable::setTestNow(BattleKit::fresh($id)->starts_at);
    BattleHttp::poll($me, $id)->assertOk();
    $playing = BattleHttp::get($friend, '')->assertOk()->assertExactJsonStructure(['active' => $compact, 'incoming', 'finished']);
    expect($playing->json('active.status'))->toBe('playing')
        ->and($playing->json('active.role'))->toBe('opponent')
        ->and($playing->json('active.opponent'))->toBe(['first_name' => 'Bek'])
        ->and(array_keys($playing->json('active')))->toBe(['id', 'status', 'mode', 'role', 'course', 'lesson', 'opponent']);

    // Over: nothing in hand, a row of "finished" for each, with the outcome from their side.
    BattleHttp::surrender($friend, $id)->assertOk();
    $mineOver = BattleHttp::get($me, '')->assertOk()->assertExactJsonStructure(['active', 'incoming', 'finished' => ['*' => $finished]]);
    $theirsOver = BattleHttp::get($friend, '')->assertOk()->assertExactJsonStructure(['active', 'incoming', 'finished' => ['*' => $finished]]);
    expect($mineOver->json('active'))->toBeNull()
        ->and($mineOver->json('finished'))->toBe([['id' => $id, 'mode' => 'sentence', 'opponent' => ['first_name' => 'Dilnoza'], 'outcome' => 'won', 'scores' => ['me' => 0, 'opponent' => 0], 'finished_at' => '2026-10-30T10:00:03.000Z']])
        ->and($theirsOver->json('finished.0.outcome'))->toBe('lost')
        ->and($theirsOver->json('finished.0.opponent'))->toBe(['first_name' => 'Bek']);
});

it('keeps the poll of a battle under 600 bytes in every state, at its fattest too - the round with both clocks, the opponent gone quiet, a result with a rematch', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $sizes = [];
    $check = function (string $label, TestResponse $response, bool $round = false, bool $rematch = false) use (&$sizes): TestResponse {
        $response->assertOk()->assertExactJsonStructure(battlePollShape(round: $round, rematch: $rematch));
        $sizes[$label] = strlen((string) $response->getContent());
        expect($sizes[$label])->toBeLessThanOrEqual(600, "poll {$label}: {$sizes[$label]} bytes");

        return $response;
    };

    $id = (int) BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course, $lesson)->json('id');
    $check('invited', BattleHttp::poll($friend, $id));
    BattleHttp::accept($friend, $id)->assertOk();
    $check('room', BattleHttp::poll($me, $id));
    BattleHttp::ready($me, $id)->assertOk();
    BattleHttp::ready($friend, $id)->assertOk();
    $check('countdown', BattleHttp::poll($me, $id), round: true)->assertJsonPath('round.phase', null);
    CarbonImmutable::setTestNow(BattleKit::fresh($id)->starts_at);
    $check('answering', BattleHttp::poll($me, $id), round: true);

    // The fattest game state: the review (both clocks set), 3-digit scores, the friend silent for 20 s (the date of the last presence).
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    BattleHttp::answer($me, $id, 1, BattleKit::correct($element))->assertOk();
    BattleHttp::answer($friend, $id, 1, BattleKit::correct($element))->assertOk();
    CarbonImmutable::setTestNow('2026-10-30 10:00:06');
    PracticeKit::resetLimiters($me);
    $fat = $check('reveal, opponent quiet', BattleHttp::poll($me, $id), round: true);
    expect($fat->json('round.phase'))->toBe('reveal')
        ->and($fat->json('round.phase_ends_at'))->not->toBeNull()
        ->and($fat->json('scores'))->toBe(['me' => 100, 'opponent' => 100]);
    CarbonImmutable::setTestNow('2026-10-30 10:00:20');
    $quiet = $check('opponent absent', BattleHttp::poll($me, $id), round: true);
    expect($quiet->json('opponent_present'))->toBeFalse()
        ->and($quiet->json('opponent_absent_since'))->not->toBeNull();

    // Over, with the rematch the other side offered; then a closed battle with the longest reason there is (`invite_expired`).
    BattleHttp::surrender($friend, $id)->assertOk();
    $source = $id;
    $offered = (int) BattleHttp::rematch($friend, $source)->assertCreated()->json('id');
    $over = $check('finished with a rematch', BattleHttp::poll($me, $source), rematch: true);
    expect($over->json('rematch'))->toBe(['battle_id' => $offered, 'status' => 'invited', 'by_me' => false])
        ->and($over->json('end_reason'))->toBe('surrender');

    BattleHttp::decline($me, $offered)->assertOk();
    CarbonImmutable::setTestNow(BattleKit::T0);
    $late = (int) BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course, $lesson)->json('id');
    CarbonImmutable::setTestNow('2026-10-30 10:05:00');
    $check('closed (invite_expired)', BattleHttp::poll($friend, $late))->assertJsonPath('end_reason', 'invite_expired');
    expect(max($sizes))->toBeLessThanOrEqual(600);
});

// ------------------------------------------------------------------------------------------------------------ the invitation, the rematch

it('validates the body of an invitation, and a rematch needs only `rematch_of` - the mode, the course, the lesson and the friendship the TWA sends along are not read, whatever they are', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // Without a rematch the friendship, the format and the course are required (each refusal counts for the limiter of the invitations).
    BattleHttp::post($me, '', [])->assertStatus(422)->assertJsonValidationErrors(['friendship_id', 'mode', 'course']);
    PracticeKit::resetLimiters($me);
    BattleHttp::post($me, '', ['friendship_id' => $friendship->id, 'mode' => 'chess', 'course' => $course->slug])->assertStatus(422)->assertJsonValidationErrors(['mode']);
    PracticeKit::resetLimiters($me);
    BattleHttp::post($me, '', ['friendship_id' => $friendship->id, 'mode' => 'pairs', 'course' => $course->slug, 'lesson_id' => 0])->assertStatus(422)->assertJsonValidationErrors(['lesson_id']);
    PracticeKit::resetLimiters($me);
    BattleHttp::post($me, '', ['rematch_of' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['rematch_of']);
    PracticeKit::resetLimiters($me);
    BattleHttp::post($me, '', ['rematch_of' => ['x']])->assertStatus(422)->assertJsonValidationErrors(['rematch_of']);
    expect(Battle::query()->count())->toBe(0);

    // A match that is over to repeat.
    $source = BattleHttp::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    BattleHttp::surrender($friend, $source)->assertOk();

    // What the TWA sends: the battle and - because it does not know better - the mode, the course, the lesson, the friendship. All of it
    // other than the truth here; none of it is read, and none of it is a 422.
    $first = BattleHttp::rematch($me, $source, ['mode' => 'pairs', 'course' => 'no-such-course', 'lesson_id' => 999999, 'friendship_id' => 424242])
        ->assertCreated()->assertExactJsonStructure(battleShape());
    expect($first->json())->toMatchArray(['status' => 'invited', 'mode' => 'collocation', 'role' => 'challenger', 'friendship_id' => $friendship->id])
        ->and($first->json('course.slug'))->toBe('english-a1')
        ->and($first->json('lesson.id'))->toBe($lesson->id)
        ->and(Battle::query()->findOrFail($first->json('id'))->rematch_of_id)->toBe($source);
    BattleHttp::cancel($me, (int) $first->json('id'))->assertOk();

    // A field of the wrong kind is only ignored too (it would not have been a mode, a course, a number).
    $second = BattleHttp::rematch($me, $source, ['mode' => 'chess', 'course' => ['x'], 'lesson_id' => 'abc', 'friendship_id' => ['nope']])->assertCreated();
    expect($second->json('mode'))->toBe('collocation')
        ->and($second->json('lesson.id'))->toBe($lesson->id);
    $secondId = (int) $second->json('id');

    // The battle that is over shows the offer to both, and who made it.
    $mineOver = BattleHttp::view($me, $source)->assertOk()->assertExactJsonStructure(battleShape(result: true, rematch: true));
    $theirsOver = BattleHttp::view($friend, $source)->assertOk()->assertExactJsonStructure(battleShape(result: true, rematch: true));
    expect($mineOver->json('rematch'))->toBe(['battle_id' => $secondId, 'status' => 'invited', 'by_me' => true])
        ->and($theirsOver->json('rematch'))->toBe(['battle_id' => $secondId, 'status' => 'invited', 'by_me' => false]);

    // What a rematch is refused for: a battle that is not mine (a stranger's looks like none), one that is still on, a friendship that is gone.
    battleError(BattleHttp::rematch(Learner::factory()->create(), $source), 404, 'battle_not_found', null);
    expect(battleError(BattleHttp::rematch($me, $secondId), 409, 'battle_wrong_state', ['status']))->toBe(['status' => 'invited']);
    $friendship->delete();
    battleError(BattleHttp::rematch($friend, $source), 403, 'not_friends', null);
});

it('refuses the second of two rematches that cross - 409 battle_busy with the invitation the asker is invited to, which they open and accept - and never names a battle the asker is not in', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $source = BattleHttp::countdown($me, $friend, $friendship, PracticeMode::Sentence, $course, $lesson);
    BattleHttp::surrender($me, $source)->assertOk();

    // The friend presses "Rematch"; a moment later so do I, with what the TWA sends along.
    $first = (int) BattleHttp::rematch($friend, $source, ['mode' => 'sentence', 'course' => $course->slug])->assertCreated()->json('id');
    $refusal = battleError(BattleHttp::rematch($me, $source, ['mode' => 'sentence', 'course' => $course->slug, 'lesson_id' => $lesson->id, 'friendship_id' => $friendship->id]), 409, 'battle_busy', ['battle_id']);
    expect($refusal)->toBe(['battle_id' => $first])
        ->and(Battle::query()->where('rematch_of_id', $source)->count())->toBe(1)
        ->and(Battle::query()->count())->toBe(2);

    // That is the invitation to open: I am its recipient; the result screen shows it as the friend's offer; I accept it.
    expect(BattleHttp::view($me, $source)->json('rematch'))->toBe(['battle_id' => $first, 'status' => 'invited', 'by_me' => false]);
    BattleHttp::view($me, $first)->assertOk()->assertJsonPath('role', 'opponent')->assertJsonPath('status', 'invited')->assertJsonPath('opponent', ['first_name' => 'Dilnoza']);
    BattleHttp::accept($me, $first)->assertOk()->assertJsonPath('status', 'room');

    // The friend is busy with it now too; a third person's invitation to the friend is none of my business, and the friend, asked to accept it, is
    // told about THEIR battle - the one they are in - and nothing else.
    $third = Learner::factory()->create(['interface_locale' => 'ru']);
    $withThird = Friendship::factory()->between($friend, $third)->create();
    $toFriend = (int) BattleHttp::invite($third, $withThird, PracticeMode::Pairs, $course)->assertCreated()->json('id');
    expect(battleError(BattleHttp::accept($friend, $toFriend), 409, 'battle_busy', ['battle_id']))->toBe(['battle_id' => $first]);
    battleError(BattleHttp::view($me, $toFriend), 404, 'battle_not_found', null);
    battleError(BattleHttp::accept($me, $toFriend), 404, 'battle_not_found', null);

    // And the friend's own invitation, repeated by a double tap, names the friend's own battle.
    BattleHttp::cancel($me, $first)->assertOk();
    $again = (int) BattleHttp::invite($friend, $friendship, PracticeMode::Sentence, $course, $lesson)->assertCreated()->json('id');
    expect(battleError(BattleHttp::invite($friend, $friendship, PracticeMode::Sentence, $course, $lesson), 409, 'battle_busy', ['battle_id']))->toBe(['battle_id' => $again]);
});

it('answers a repeated request again with the first answer - round resolved, next round begun, match over - and refuses only a NEW request, with the details of docs/16 §7.6', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // Countdown: the round exists, its phase has not begun.
    $id = BattleHttp::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    expect(battleError(BattleHttp::answer($me, $id, 1, BattleKit::correct($element)), 409, 'round_not_open', ['round', 'phase']))->toBe(['round' => 1, 'phase' => null]);

    CarbonImmutable::setTestNow(BattleKit::fresh($id)->starts_at);
    BattleHttp::poll($me, $id)->assertOk()->assertJsonPath('status', 'playing');
    BattleHttp::answer($me, $id, 1, BattleKit::correct($element))->assertOk();

    // The friend answers second: the round is resolved. The reply is lost; the client sends the very same request again.
    $request = (string) Str::uuid();
    $first = BattleHttp::answer($friend, $id, 1, BattleKit::wrong($element), $request)->assertOk();
    expect($first->json('round_resolved'))->toBeTrue();
    foreach ([BattleKit::wrong($element), BattleKit::correct($element)] as $payload) {   // a repeat with another body is answered with the first
        $repeat = BattleHttp::answer($friend, $id, 1, $payload, $request)->assertOk();
        expect($repeat->json('verdict'))->toBe('wrong')
            ->and($repeat->json('points'))->toBe(0)
            ->and($repeat->json('accepted_at'))->toBe($first->json('accepted_at'))
            ->and($repeat->json('round_resolved'))->toBeTrue()
            ->and($repeat->json('poll.round.phase'))->toBe('reveal');
    }

    // The next round has begun (the round of the request is behind): the repeat is still the first answer, not a 409.
    battleWait($id, $me, $friend, 4);
    expect(BattleHttp::view($me, $id)->json('round.no'))->toBe(2);
    $late = BattleHttp::answer($friend, $id, 1, BattleKit::wrong($element), $request)->assertOk();
    expect([$late->json('verdict'), $late->json('points'), $late->json('round_resolved'), $late->json('poll.round.no')])->toBe(['wrong', 0, true, 2]);

    // A NEW request is another thing: for the round behind it is 409 round_closed, for one ahead round_not_open - with the round and the phase.
    expect(battleError(BattleHttp::answer($friend, $id, 1, BattleKit::wrong($element)), 409, 'round_closed', ['round', 'phase']))->toBe(['round' => 1, 'phase' => 'answering'])
        ->and(battleError(BattleHttp::answer($friend, $id, 3, BattleKit::wrong($element)), 409, 'round_not_open', ['round', 'phase']))->toBe(['round' => 3, 'phase' => 'answering']);

    // A second answer to the round that is on: the details tell the first one (verdict and points) - the answer itself stands.
    $next = BattleKit::element(BattleKit::fresh($id), 2);
    BattleHttp::answer($me, $id, 2, BattleKit::correct($next))->assertOk();
    expect(battleError(BattleHttp::answer($me, $id, 2, BattleKit::wrong($next)), 409, 'answer_already_accepted', ['verdict', 'points']))->toBe(['verdict' => 'correct', 'points' => 100]);

    // The shape of an answer is the mode's to check: 422 with what it expected; an id that is not in the round is 422 too.
    expect(battleError(BattleHttp::answer($friend, $id, 2, ['option' => 'x']), 422, 'invalid_answer_shape', ['expected']))->toBe(['expected' => 'option_id']);
    battleError(BattleHttp::answer($friend, $id, 2, ['option_id' => 'eNoSuchOpt0']), 422, 'unknown_element_id', null);
    BattleHttp::post($friend, "/{$id}/answer", ['request_id' => 'not-a-uuid', 'round' => 2, 'answer' => ['skip' => true]])->assertStatus(422)->assertJsonValidationErrors(['request_id']);
    BattleHttp::post($friend, "/{$id}/answer", ['request_id' => (string) Str::uuid(), 'round' => 0, 'answer' => ['skip' => true]])->assertStatus(422)->assertJsonValidationErrors(['round']);
    BattleHttp::post($friend, "/{$id}/answer", ['request_id' => (string) Str::uuid(), 'round' => 2])->assertStatus(422)->assertJsonValidationErrors(['answer']);
    BattleHttp::post($friend, "/{$id}/answer", ['request_id' => (string) Str::uuid(), 'round' => 2, 'answer' => ['text' => str_repeat('x', 201)]])->assertStatus(422)->assertJsonValidationErrors(['answer.text']);

    // The end of the match: the very same request is still answered with the first answer, and a new one is "the battle is closed".
    BattleHttp::answer($friend, $id, 2, ['skip' => true])->assertOk();
    for ($round = 3; $round <= 5; $round++) {
        battleWait($id, $me, $friend, 4);
        $each = BattleKit::element(BattleKit::fresh($id), $round);
        BattleHttp::answer($me, $id, $round, BattleKit::correct($each))->assertOk();
        BattleHttp::answer($friend, $id, $round, BattleKit::correct($each))->assertOk();
    }
    battleWait($id, $me, $friend, 4);
    expect(BattleHttp::view($me, $id)->json('status'))->toBe('finished');
    $over = BattleHttp::answer($friend, $id, 1, BattleKit::wrong($element), $request)->assertOk();
    expect([$over->json('verdict'), $over->json('points'), $over->json('round_resolved'), $over->json('poll.status')])->toBe(['wrong', 0, true, 'finished']);
    expect(battleError(BattleHttp::answer($friend, $id, 5, BattleKit::wrong($element)), 409, 'battle_closed', ['end_reason']))->toBe(['end_reason' => 'completed']);
});

it('warns over HTTP that the opponent has lost the connection after 10 s - since when, and how long the screen has - and drops the warning when they poll', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);   // 10:00:03; the friend was last here at 10:00:00

    CarbonImmutable::setTestNow('2026-10-30 10:00:10');
    $near = BattleHttp::poll($me, $id)->assertOk();
    expect([$near->json('opponent_present'), $near->json('opponent_absent_since')])->toBe([true, null]);

    CarbonImmutable::setTestNow('2026-10-30 10:00:11');
    $quiet = BattleHttp::poll($me, $id)->assertOk()->assertExactJsonStructure(battlePollShape(round: true));
    $view = BattleHttp::view($me, $id)->assertOk();
    expect([$quiet->json('status'), $quiet->json('opponent_present'), $quiet->json('opponent_absent_since')])->toBe(['playing', false, '2026-10-30T10:00:00.000Z'])
        ->and([$view->json('opponent_present'), $view->json('opponent_absent_since')])->toBe([false, '2026-10-30T10:00:00.000Z'])
        ->and([$view->json('presence_warn_seconds'), $view->json('presence_grace_seconds')])->toBe([10, 30])
        // what the screen counts: the match ends `presence_grace_seconds` after the last presence
        ->and(30 - (CarbonImmutable::parse($view->json('server_now'))->getTimestamp() - CarbonImmutable::parse($view->json('opponent_absent_since'))->getTimestamp()))->toBe(19);

    // The friend polls: the heartbeat is written, the warning is gone, the match never stopped.
    BattleHttp::poll($friend, $id)->assertOk();
    CarbonImmutable::setTestNow('2026-10-30 10:00:12');
    $back = BattleHttp::poll($me, $id)->assertOk();
    expect([$back->json('status'), $back->json('opponent_present'), $back->json('opponent_absent_since')])->toBe(['playing', true, null]);

    // The end is still the grace, not the warning: silent for 30 s the match goes on, past it the one who is here wins.
    CarbonImmutable::setTestNow('2026-10-30 10:00:41');   // 29 s after the friend's last poll
    expect(BattleHttp::poll($me, $id)->json('status'))->toBe('playing');
    CarbonImmutable::setTestNow('2026-10-30 10:00:43');
    $gone = BattleHttp::poll($me, $id)->assertOk();
    expect([$gone->json('status'), $gone->json('end_reason')])->toBe(['finished', 'absent']);
    expect(BattleHttp::view($me, $id)->json('result.outcome'))->toBe('won');
});
