<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupRoundService;
use App\Models\CupDay;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/04 A.23 (K7): the exact shape of every answer of the routes of the cup - the contract of the screens of K8 - and the keys of the
 * snapshot that never reach a round before its task is answered (the right answer, the word, the ids of the database, the audio path,
 * the seed). The `reveal` of an answered task and the `review` of a finished round carry the right answer by right.
 */

uses(RefreshDatabase::class);

/** The keys of the snapshot (and of the server) that must not appear in a round at any depth (docs/33 §11.4). */
const CUP_FORBIDDEN_KEYS = [
    'correct_option_id', 'expected', 'correct_text', 'full_text', 'word_id', 'word', 'lexeme_id', 'audio_path', 'audio_disk',
    'accepted', 'seed', 'seed_secret', 'word_plan', 'learner_id', 'telegram_user_id', 'public_code', 'snapshot', 'order', 'state',
];

const CUP_FIELDS = ['id', 'status', 'group_title', 'level', 'days', 'counted_days', 'round_tasks', 'round_minutes', 'timezone', 'starts_at', 'ends_at', 'day', 'summaries_enabled', 'summary_time', 'paused', 'next_day_at'];

const CUP_RULES = ['points_per_answer', 'typo_points', 'max_day_points', 'counted_days', 'days', 'max_total'];

const CUP_ROUND = ['attempt_id', 'cup_id', 'participant_id', 'day', 'status', 'finish_reason', 'total', 'answered', 'correct', 'points', 'max_points', 'position', 'started_at', 'expires_at', 'server_now', 'current', 'answers', 'review', 'next_day_at'];

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function cupContract(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

/** Every string key at any depth. @return list<string> */
function cupContractKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        array_push($keys, ...cupContractKeys($item));
    }

    return $keys;
}

/** The keys of the current task of each kind: the projection of the game plus the position (and the clip of a `listen`). */
function cupTaskKeys(string $kind): array
{
    return match ($kind) {
        'translate_fwd' => ['position', 'id', 'kind', 'prompt', 'audio', 'options'],
        'translate_back' => ['position', 'id', 'kind', 'prompt', 'options'],
        'listen' => ['position', 'id', 'kind', 'audio', 'options', 'audio_url'],
        'spell' => ['position', 'id', 'kind', 'prompt', 'answer_length'],
        'gap' => ['position', 'id', 'kind', 'masked', 'translation'],
    };
}

/** Turns the first "back" task of the day into a `listen` task with a recording, as the core writes it for a word with audio. */
function cupMakeListen(int $cupDayId): string
{
    $snapshot = json_decode((string) DB::table('cup_days')->where('id', $cupDayId)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($snapshot['elements'] as $i => $element) {
        // A "back" task has the words as its options - the very options of a listen task.
        if ($element['kind'] === 'translate_back') {
            unset($element['prompt'], $element['audio']);
            $snapshot['elements'][$i] = [...$element, 'kind' => 'listen', 'audio_disk' => 'public', 'audio_path' => 'audio/cup-test.mp3'];
            DB::table('cup_days')->where('id', $cupDayId)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

            return (string) $element['id'];
        }
    }

    throw new RuntimeException('No task with options on the day.');
}

it('shapes the invitation, my cups, the table and the state of today exactly', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);

    $invite = cupContract($a, 'GET', "/cups/by-code/{$cup->public_code}")->assertOk()->json();
    expect(array_keys($invite))->toBe([...CUP_FIELDS, 'participants_count', 'rules', 'me'])
        ->and(array_keys($invite['rules']))->toBe(CUP_RULES)
        ->and(array_keys($invite['me']))->toBe(['joined', 'participant_id', 'status', 'display_name', 'left'])
        ->and($invite['participants_count'])->toBe(2)
        ->and($invite['summary_time'])->toBe('20:00')
        ->and($invite['starts_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/');

    $list = cupContract($a, 'GET', '/cups')->assertOk()->json();
    expect(array_keys($list))->toBe(['cups'])
        ->and(array_keys($list['cups'][0]))->toBe([...CUP_FIELDS, 'me'])
        ->and(array_keys($list['cups'][0]['me']))->toBe(['participant_id', 'display_name', 'status', 'total', 'days_played', 'final_rank', 'today'])
        ->and(array_keys($list['cups'][0]['me']['today']))->toBe(['state', 'day']);

    $board = cupContract($a, 'GET', "/cups/{$cup->id}")->assertOk()->json();
    expect(array_keys($board))->toBe(['cup', 'standings', 'me', 'can_manage', 'server_now'])
        ->and(array_keys($board['cup']))->toBe([...CUP_FIELDS, 'rules'])
        ->and(array_keys($board['standings'][0]))->toBe(['participant_id', 'rank', 'display_name', 'pro_emoji', 'total', 'round_points', 'days_played', 'left', 'me'])
        ->and(array_keys($board['me']))->toBe(['participant_id', 'display_name', 'status', 'first_day', 'renames_left', 'total', 'final_rank', 'today', 'days'])
        ->and(array_keys($board['me']['today']))->toBe(['state', 'day', 'attempt_id', 'points', 'expires_at'])
        ->and($board['me']['days'])->toHaveCount(7)
        ->and(array_keys($board['me']['days'][0]))->toBe(['day', 'points', 'round_points', 'duel_points', 'played', 'counted', 'available'])
        ->and(array_intersect(cupContractKeys($board), ['learner_id', 'telegram_user_id', 'public_code', 'seed_secret']))->toBe([]);

    $today = cupContract($a, 'GET', "/cups/{$cup->id}/rounds/today")->assertOk()->json();
    expect($today)->toBe(['state' => 'not_started', 'day' => 1, 'attempt_id' => null, 'points' => null, 'expires_at' => null, 'server_now' => $today['server_now']]);
});

it('shapes joining, the name and the screens of an administrator exactly', function (): void {
    CarbonImmutable::setTestNow('2026-12-01 06:00:00');
    $cup = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    $learner = Learner::factory()->create();
    CupKit::member($learner, 'administrator');

    $joined = cupContract($learner, 'POST', "/cups/{$cup->id}/join", ['display_name' => 'Malika'])->assertCreated()->json();
    expect($joined)->toBe([
        'participant_id' => $joined['participant_id'], 'cup_id' => $cup->id, 'display_name' => 'Malika', 'status' => 'active', 'first_day' => 1, 'renames_left' => 3,
    ]);
    expect(array_keys(cupContract($learner, 'PATCH', "/cups/{$cup->id}/me", ['display_name' => 'Mali'])->assertOk()->json()))->toBe(array_keys($joined));

    $setup = cupContract($learner, 'GET', "/cups/setup/{$cup->public_code}")->assertOk()->json();
    expect(array_keys($setup))->toBe([...CUP_FIELDS, 'locale', 'summary_minute', 'cancel_reason', 'options', 'rules'])
        ->and($setup['options'])->toBe(['levels' => ['A1', 'A2', 'B1', 'B2', 'C1'], 'locales' => ['ru', 'uz']]);
    expect(array_keys(cupContract($learner, 'PATCH', "/cups/{$cup->id}/settings", ['summaries_enabled' => false])->assertOk()->json()))->toBe(array_keys($setup))
        ->and(array_keys(cupContract($learner, 'POST', "/cups/{$cup->id}/cancel")->assertOk()->json()))->toBe(array_keys($setup));
});

it('shapes the round: the start, the resume, an answer, the review - and no right answer before the task is answered', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);

    $start = cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $again = cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertOk()->json();
    $resume = cupContract($a, 'GET', "/cup-rounds/{$start['attempt_id']}")->assertOk()->json();
    expect(array_keys($start))->toBe(CUP_ROUND)
        ->and($again['attempt_id'])->toBe($start['attempt_id'])
        ->and($resume['expires_at'])->toBe($start['expires_at'])
        ->and(array_keys($start['current']))->toBe(cupTaskKeys($start['current']['kind']))
        ->and(array_intersect(cupContractKeys([$start, $again, $resume]), CUP_FORBIDDEN_KEYS))->toBe([]);

    $dayId = (int) CupRoundAttempt::query()->whereKey($start['attempt_id'])->value('cup_day_id');
    $round = $start;
    $answers = [];
    for ($i = 0; $i < 10; $i++) {
        $element = $round['current']['id'];
        $body = $i % 2 === 0 ? CupKit::rightAnswer($dayId, $element) : CupKit::wrongAnswer($dayId, $element);
        $answer = cupContract($a, 'POST', "/cup-rounds/{$start['attempt_id']}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => $body])->assertOk()->json();
        $answers[] = $answer;
        expect(array_keys($answer))->toBe(['verdict', 'points', 'reveal', 'finished', 'total_points', 'replayed', 'round'])
            ->and(array_keys($answer['round']))->toBe(CUP_ROUND);
        $round = $answer['round'];
        if ($round['current'] !== null) {
            // The next task is a question, never its answer.
            expect(array_keys($round['current']))->toBe(cupTaskKeys($round['current']['kind']))
                ->and(array_intersect(cupContractKeys($round['current']), CUP_FORBIDDEN_KEYS))->toBe([])
                ->and($round['review'])->toBeNull();
        }
    }

    expect($answers[0]['verdict'])->toBe('correct')
        ->and($answers[0]['points'])->toBe(2)
        ->and($answers[1]['points'])->toBe(0)
        ->and($answers[9]['finished'])->toBeTrue()
        ->and($answers[9]['total_points'])->toBe(10)
        ->and($round['status'])->toBe('finished')
        ->and($round['finish_reason'])->toBe('completed')
        ->and($round['current'])->toBeNull()
        ->and($round['review'])->toHaveCount(10)
        ->and(array_keys($round['review'][0]))->toBe(['element_id', 'kind', 'verdict', 'points', 'answer', 'reveal'])
        ->and(array_column($round['answers'], 'points'))->toBe([2, 0, 2, 0, 2, 0, 2, 0, 2, 0]);
    // The round of the day is played: the start says so with the points.
    expect(cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertStatus(409)->json('error'))
        ->toBe(['code' => 'cup_round_done', 'message' => 'The round of the day is played.', 'details' => ['points' => 10]]);
});

it('gives a listen task the clip of its attempt and refuses the clip of a task not reached yet', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('audio/cup-test.mp3', 'ID3-fake-mp3');
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $day = CupDay::query()->where('cup_id', $cup->id)->where('day', 1)->firstOrFail();
    app(CupRoundService::class)->ensureDaySnapshot($day, $cup);
    $listen = cupMakeListen($day->id);

    $start = cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $attempt = CupRoundAttempt::query()->findOrFail($start['attempt_id']);
    $round = $start;
    while ($round['current'] !== null && $round['current']['id'] !== $listen) {
        // Before it is reached the clip is not handed out: the same 404 as a stranger's attempt.
        cupContract($a, 'GET', "/cup-rounds/{$attempt->id}/audio/{$listen}")->assertNotFound()->assertJsonPath('error.code', 'cup_round_not_found');
        $round = cupContract($a, 'POST', "/cup-rounds/{$attempt->id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $round['current']['id'], 'answer' => ['skip' => true]])->json('round');
    }

    expect($round['current']['kind'])->toBe('listen')
        ->and(array_keys($round['current']))->toBe(cupTaskKeys('listen'))
        ->and($round['current']['audio_url'])->toBe("/api/twa/cup-rounds/{$attempt->id}/audio/{$listen}")
        ->and(array_intersect(cupContractKeys($round['current']), CUP_FORBIDDEN_KEYS))->toBe([]);
    $clip = cupContract($a, 'GET', $round['current']['audio_url'] === null ? '' : substr($round['current']['audio_url'], strlen('/api/twa')));
    $clip->assertOk()->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('Cache-Control', 'no-store, private');
    expect($clip->streamedContent())->toBe('ID3-fake-mp3');

    // An element that is not in the attempt at all: 404.
    cupContract($a, 'GET', "/cup-rounds/{$attempt->id}/audio/eNOPE")->assertNotFound();
});

it('refuses a malformed answer with 422 before the core, and a wrong task with the core\'s codes', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $start = cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $id = $start['attempt_id'];
    $element = $start['current']['id'];

    cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['element_id' => $element, 'answer' => ['skip' => true]])->assertStatus(422)->assertJsonValidationErrors('request_id');
    cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => ['points' => 2]])->assertStatus(422)->assertJsonValidationErrors('answer');
    cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => ['text' => str_repeat('a', 201)]])->assertStatus(422);

    $next = CupRoundAttempt::query()->findOrFail($id)->order[1];
    expect(cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $next, 'answer' => ['skip' => true]])->assertStatus(409)->json('error'))
        ->toBe(['code' => 'element_not_current', 'message' => 'Answer the current task.', 'details' => ['current' => $element]]);

    $request = (string) Str::uuid();
    $first = cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => $request, 'element_id' => $element, 'answer' => ['skip' => true]])->assertOk()->json();
    $replay = cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => strtoupper($request), 'element_id' => $element, 'answer' => ['skip' => true]])->assertOk()->json();
    expect($replay['replayed'])->toBeTrue()
        ->and(array_diff_key($replay, ['replayed' => 1, 'round' => 1]))->toBe(array_diff_key($first, ['replayed' => 1, 'round' => 1]))
        ->and(cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => ['skip' => true]])->assertStatus(409)->json('error.code'))->toBe('answer_already_accepted');

    // Past the deadline (+ 1.5 s): the attempt closes, the points of the accepted answers stay.
    CarbonImmutable::setTestNow(CarbonImmutable::parse($start['expires_at'])->addSeconds(2));
    $current = CupRoundAttempt::query()->findOrFail($id)->order[1];
    expect(cupContract($a, 'POST', "/cup-rounds/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $current, 'answer' => ['skip' => true]])->assertStatus(409)->json('error'))
        ->toMatchArray(['code' => 'cup_round_expired', 'details' => ['points' => 0]])
        ->and(cupContract($a, 'GET', "/cup-rounds/{$id}")->assertOk()->json('finish_reason'))->toBe('expired');
});

it('takes nothing from the client as a fact: the time, the points and the verdict are the server\'s', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $start = cupContract($a, 'POST', "/cups/{$cup->id}/rounds/today", ['expires_at' => '2030-01-01T00:00:00Z', 'order' => ['x']])->assertCreated()->json();
    $element = $start['current']['id'];
    $dayId = (int) CupRoundAttempt::query()->whereKey($start['attempt_id'])->value('cup_day_id');

    $answer = cupContract($a, 'POST', "/cup-rounds/{$start['attempt_id']}/answer", [
        'request_id' => (string) Str::uuid(),
        'element_id' => $element,
        'answer' => CupKit::wrongAnswer($dayId, $element),
        'points' => 20, 'verdict' => 'correct', 'time_spent_ms' => 1, 'now' => '2026-12-01T19:01:00Z',
    ])->assertOk()->json();

    expect($answer['verdict'])->toBe('wrong')
        ->and($answer['points'])->toBe(0)
        ->and($start['expires_at'])->toBe(CarbonImmutable::now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s.v\Z'))
        ->and(DB::table('cup_day_scores')->sum('round_points'))->toBe(0);
});
