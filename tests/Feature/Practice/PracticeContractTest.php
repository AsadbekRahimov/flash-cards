<?php

declare(strict_types=1);

/*
 * JSON contract of the practice API (docs/16 §7, resources/twa/src/api/types.ts): the exact key structure of every answer, no
 * `data` envelope, `details` only where docs/16 §7.6 describes it, and no key of the snapshot in a response (§2.8, §12).
 * If a test here fails, the TWA client breaks - change the client and docs/04 together.
 */

use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Http\Resources\ApiErrorResource;
use App\Models\Battle;
use App\Models\Learner;
use App\Models\PracticeSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\PracticeKit;

uses(RefreshDatabase::class);

const CONTRACT_PRACTICE_COURSE = ['slug', 'title'];
const CONTRACT_PRACTICE_LESSON = ['id', 'stage', 'number', 'title'];
const CONTRACT_PRACTICE_PROGRESS = ['done', 'total'];
const CONTRACT_PRACTICE_MODE_ROW = ['mode', 'state', 'available', 'required', 'reason', 'estimate_seconds'];
const CONTRACT_PRACTICE_SUMMARY = ['total', 'answered', 'correct_first', 'typos', 'hints', 'mismatches', 'duration_ms', 'finish_reason', 'retry_available'];
const CONTRACT_PRACTICE_MISTAKE = ['element_id', 'kind', 'label', 'your_answer', 'verdict'];

/**
 * The keys that live in the snapshot only: the expected answers, the ids of the database, the audio path (docs/16 §2.8).
 * They must not appear ANYWHERE in the response of the hub or of a session - at any depth.
 */
const CONTRACT_FORBIDDEN_PRACTICE_KEYS = [
    'pairs', 'correct_ids', 'accepted', 'accepted_display', 'expected', 'full_text',
    'audio_path', 'audio_disk', 'word_id', 'version_id', 'item_id', 'pool', 'seed',
];

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The structure of a session as the TWA reads it; `$current` is the structure of the current element (null = it has none). */
function contractSession(?array $current, bool $lesson = false, bool $summary = false): array
{
    return [
        'id', 'status', 'mode', 'source', 'retry_of',
        'course' => CONTRACT_PRACTICE_COURSE,
        ...($lesson ? ['lesson' => CONTRACT_PRACTICE_LESSON] : ['lesson']),
        'progress' => CONTRACT_PRACTICE_PROGRESS,
        'counters' => ['correct_first', 'hints', 'mismatches'],
        'expires_at', 'server_now',
        ...($current === null ? ['current'] : ['current' => $current]),
        ...($summary ? ['summary' => [...CONTRACT_PRACTICE_SUMMARY, 'mistakes' => ['*' => CONTRACT_PRACTICE_MISTAKE]]] : ['summary']),
    ];
}

/** Every key of a decoded JSON at any depth. @return list<string> */
function contractKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = [...$keys, ...contractKeys($item)];
    }

    return $keys;
}

function contractWorld(): array
{
    [$course, $lesson] = PracticeKit::course();

    return [Learner::factory()->create(), $course, $lesson];
}

function contractOpen(Learner $learner, string $course, string $mode, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => $mode, 'course' => $course, ...$body]);
}

function contractAnswer(Learner $learner, array $session, array $answer): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
        'request_id' => (string) Str::uuid(),
        'element_id' => $session['current']['id'],
        'answer' => $answer,
    ]);
}

/** An answer to a NAMED element (`contractAnswer` answers the current one). @param array<string, mixed> $answer */
function contractAnswerElement(Learner $learner, int $sessionId, string $elementId, array $answer): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", [
        'request_id' => (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => $answer,
    ]);
}

/** A swap of a NAMED element of a dictation. */
function contractReplace(Learner $learner, int $sessionId, string $elementId): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/replace", [
        'request_id' => (string) Str::uuid(),
        'element_id' => $elementId,
        'reason' => 'audio_unavailable',
    ]);
}

/**
 * The error of a REAL request, on the wire (docs/16 §7.6, docs/04 A.6): `{"error": {"code", "message"[, "details"]}}` and
 * nothing else at the top; `details` INSIDE `error`, only when there is something to say, with exactly the keys the document
 * names. The TWA client reads them (`ApiClientError.details`): a key renamed here breaks a screen there.
 *
 * @param  list<string>|null  $keys  the keys of `details` (null: there must be no `details` at all)
 * @return array<string, mixed> `error.details` (empty when there is none)
 */
function contractError(TestResponse $response, int $status, string $code, ?array $keys): array
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

it('GET /practice/hub keeps its exact JSON shape, for a lesson and for a whole course', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course, $lesson] = contractWorld();
    $headers = PracticeKit::headers($learner);

    $response = $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")
        ->assertOk()
        ->assertExactJsonStructure([
            'course' => CONTRACT_PRACTICE_COURSE,
            'lesson' => CONTRACT_PRACTICE_LESSON,
            'modes' => ['*' => CONTRACT_PRACTICE_MODE_ROW],
            'active_session',
            'battle' => ['enabled', 'active_battle_id', 'incoming_count'],
        ]);

    expect($response->json('modes'))->toHaveCount(4)
        ->and(array_column($response->json('modes'), 'mode'))->toBe(['pairs', 'collocation', 'sentence', 'dictation'])
        ->and($response->json('lesson'))->toBe(['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'])
        ->and($response->json('battle'))->toBe(['enabled' => true, 'active_battle_id' => null, 'incoming_count' => 0]);

    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")
        ->assertOk()
        ->assertExactJsonStructure(['course' => CONTRACT_PRACTICE_COURSE, 'lesson', 'modes' => ['*' => CONTRACT_PRACTICE_MODE_ROW], 'active_session', 'battle' => ['enabled', 'active_battle_id', 'incoming_count']])
        ->assertJsonPath('lesson', null);

    $session = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();
    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")
        ->assertOk()
        ->assertExactJsonStructure([
            'course' => CONTRACT_PRACTICE_COURSE, 'lesson', 'modes' => ['*' => CONTRACT_PRACTICE_MODE_ROW],
            'active_session' => ['id', 'mode', 'course', 'lesson_id', 'progress' => CONTRACT_PRACTICE_PROGRESS],
            'battle' => ['enabled', 'active_battle_id', 'incoming_count'],
        ])
        ->assertJsonPath('active_session.id', $session['id']);
});

it('POST /practice/sessions and GET /practice/sessions/{id} keep their exact JSON shape, for a lesson and for a course', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course, $lesson] = contractWorld();
    $current = ['id', 'kind', 'prompt'];

    $opened = contractOpen($learner, $course->slug, 'collocation', ['lesson_id' => $lesson->id])
        ->assertCreated()->assertExactJsonStructure(contractSession($current, lesson: true))->json();
    $this->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$opened['id']}")
        ->assertOk()->assertExactJsonStructure(contractSession($current, lesson: true));

    PracticeSession::query()->delete();
    contractOpen($learner, $course->slug, 'collocation')->assertCreated()->assertExactJsonStructure(contractSession($current))->assertJsonPath('lesson', null);

    expect($opened['expires_at'])->toBe('2026-10-31T10:00:00.000Z')
        ->and($opened['server_now'])->toBe('2026-10-30T10:00:00.000Z');
});

it('POST /practice/sessions/{id}/answer keeps its exact JSON shape: a final answer, a matching of pairs, the last answer', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    [$learner, $course] = contractWorld();
    $reveal = ['expected', 'your_text', 'translation', 'explanation'];

    // A final answer: the verdict, the reveal, no pair, no `round_done`.
    $session = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();
    $answer = contractAnswer($learner, $session, ['text' => 'nonsense'])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession(['id', 'kind', 'prompt'])]);
    expect($answer->json('verdict'))->toBe('wrong')->and($answer->json('pair'))->toBeNull();

    // The last answer: the session is over, `current` is gone, the summary is there.
    $session = $answer->json('session');
    $session = contractAnswer($learner, $session, ['skip' => true])->assertOk()->json('session');
    contractAnswer($learner, $session, ['text' => 'x'])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession(null, summary: true)])
        ->assertJsonPath('session.status', 'finished');

    // A matching of pairs: no reveal, a pair, `round_done`.
    PracticeSession::query()->delete();
    $pairs = contractOpen($learner, $course->slug, 'pairs')->assertCreated()
        ->assertExactJsonStructure(contractSession(['id', 'kind', 'left' => ['*' => ['id', 'text']], 'right' => ['*' => ['id', 'text' => ['ru']]], 'matched', 'mismatches']))->json();
    $round = PracticeSession::query()->findOrFail($pairs['id'])->snapshot['elements'][0];
    $left = $round['left'][0]['id'];

    test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$pairs['id']}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $round['id'], 'answer' => ['left_id' => $left, 'right_id' => $round['pairs'][$left]],
    ])->assertOk()->assertExactJsonStructure([
        'verdict', 'first_attempt', 'reveal', 'pair' => ['left_id', 'right_id'], 'round_done',
        'session' => contractSession(['id', 'kind', 'left' => ['*' => ['id', 'text']], 'right' => ['*' => ['id', 'text' => ['ru']]], 'matched', 'mismatches']),
    ])->assertJsonPath('reveal', null)->assertJsonPath('round_done', false)->assertJsonPath('session.current.matched.0', [$left, $round['pairs'][$left]]);
});

it('POST /practice/sessions/{id}/replace keeps the shape of a session', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 6, spares: 2);
    [$learner, $course] = contractWorld();
    $current = ['id', 'kind', 'source', 'audio_url', 'words', 'replacements_left'];

    $session = contractOpen($learner, $course->slug, 'dictation')->assertCreated()->assertExactJsonStructure(contractSession($current))->json();
    $this->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/replace", [
        'request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'reason' => 'audio_unavailable',
    ])->assertOk()->assertExactJsonStructure(contractSession($current));
});

it('keeps the exact JSON shape of the REAL dictation: an element without a text, the review only with the answer', function (): void {
    Storage::fake('practice_audio');
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $headers = PracticeKit::headers($learner);
    $current = ['id', 'kind', 'source', 'audio_url', 'words', 'replacements_left'];
    $review = ['expected', 'your_text', 'diff' => ['*' => ['expected', 'got', 'ok']], 'translation', 'explanation'];

    $opened = contractOpen($learner, $course->slug, 'dictation', ['lesson_id' => $lesson->id])->assertCreated()
        ->assertExactJsonStructure(contractSession($current, lesson: true))->json();
    $read = $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$opened['id']}")->assertOk()
        ->assertExactJsonStructure(contractSession($current, lesson: true))->json();
    $hub = $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json();
    $elements = PracticeSession::query()->findOrFail($opened['id'])->snapshot['elements'];

    // Before any answer: no key of the snapshot anywhere, and no text of any element, in the session, the hub or a refusal.
    $refused = contractAnswerElement($learner, $opened['id'], $elements[3]['id'], ['text' => 'anything']);
    expect(contractError($refused, 409, 'element_not_current', ['current']))->toBe(['current' => $elements[0]['id']]);
    foreach ([$opened, $read, $hub, $refused->json()] as $body) {
        expect(array_values(array_intersect(contractKeys($body), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
        foreach ($elements as $element) {
            expect(json_encode($body, JSON_THROW_ON_ERROR))->not->toContain($element['display'])->not->toContain($element['audio_path']);
        }
    }

    // A typo: the review has the text of THIS element and the comparison word by word; the session next to it has none of the next one.
    $first = $elements[0]['display'];
    $typo = contractAnswerElement($learner, $opened['id'], $elements[0]['id'], ['text' => substr($first, 0, 2).$first[3].$first[2].substr($first, 4)])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $review, 'pair', 'session' => contractSession($current, lesson: true)]);
    expect($typo->json('verdict'))->toBe('typo')
        ->and($typo->json('reveal.expected'))->toBe($first)
        ->and($typo->json('pair'))->toBeNull()
        ->and(array_values(array_intersect(contractKeys($typo->json('session')), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([])
        ->and(json_encode($typo->json('session'), JSON_THROW_ON_ERROR))->not->toContain($elements[1]['display'])->not->toContain($first);

    // A skip: the same keys, nothing written and no comparison.
    contractAnswerElement($learner, $opened['id'], $elements[1]['id'], ['skip' => true])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => ['expected', 'your_text', 'diff', 'translation', 'explanation'], 'pair', 'session' => contractSession($current, lesson: true)])
        ->assertJsonPath('reveal.your_text', null)
        ->assertJsonPath('reveal.diff', null);
});

it('keeps the practice block of GET /me in its exact shape', function (): void {
    [$learner] = contractWorld();

    $this->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')
        ->assertOk()
        ->assertJsonStructure(['practice' => ['available', 'pro_required', 'modes', 'battle']])
        ->assertJsonPath('practice.available', true);
});

it('shows no key of the snapshot in any response, at any depth, and none of its secrets before the answer', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 5);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 5, spares: 2);
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    [$learner, $course, $lesson] = contractWorld();
    $headers = PracticeKit::headers($learner);
    $seen = [];

    foreach (['collocation', 'dictation', 'pairs'] as $mode) {
        PracticeSession::query()->delete();
        $session = contractOpen($learner, $course->slug, $mode, ['lesson_id' => $lesson->id])->assertCreated()->json();
        $seen[] = $session;
        $seen[] = $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->json();
        $seen[] = $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json();

        // Nothing the snapshot knows of the OTHER elements is in the response: their expected answers, ids, the audio path.
        $snapshot = PracticeSession::query()->findOrFail($session['id'])->snapshot;
        $json = json_encode($session, JSON_THROW_ON_ERROR);
        foreach ($snapshot['elements'] as $element) {
            foreach (['expected', 'full_text'] as $secret) {
                if (isset($element[$secret])) {
                    expect($json)->not->toContain((string) $element[$secret]);
                }
            }
            foreach ($element['correct_ids'] ?? [] as $secret) {
                expect($json)->not->toContain($secret);
            }
            if (isset($element['audio_path'])) {
                expect($json)->not->toContain($element['audio_path']);
            }
        }

        // The answer shows the verdict and the reveal of THE element, and nothing of the next one.
        if ($mode === 'collocation') {
            $first = $snapshot['elements'][0];
            $second = $snapshot['elements'][1];
            $answer = contractAnswer($learner, $session, ['text' => 'nonsense'])->assertOk();
            $seen[] = $answer->json('session');
            expect($answer->json('reveal.expected'))->toBe($first['expected'])
                ->and(json_encode($answer->json('session'), JSON_THROW_ON_ERROR))->not->toContain($second['expected'])->not->toContain($first['expected']);
        }
    }

    foreach ($seen as $body) {
        expect(array_values(array_intersect(contractKeys($body), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
    }
});

it('cleans the keys of the snapshot out of the current element even when a handler lets them through', function (): void {
    $fake = PracticeKit::fake(PracticeMode::Collocation);
    $fake->leak = true;
    [$learner, $course] = contractWorld();

    $session = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();

    // The keys the handler let through are gone at any depth (`nested` was an object made of two of them: nothing is left in it).
    expect(array_keys($session['current']))->toBe(['id', 'kind', 'prompt', 'nested'])
        ->and($session['current']['nested'])->toBe([])
        ->and(array_values(array_intersect(contractKeys($session), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
});

it('wraps every error of the practice in the envelope, with `details` exactly where docs/16 §7.6 has them', function (): void {
    $cases = [
        // code => [the exception, the keys of `details` (null: the key is absent)]
        'practice_unavailable' => [PracticeException::unavailable(), null],
        'practice_pro_required' => [PracticeException::proRequired(), null],
        'practice_mode_disabled' => [PracticeException::modeDisabled(PracticeMode::Pairs), ['mode']],
        'practice_not_enough_material' => [PracticeException::notEnoughMaterial(PracticeMode::Pairs, new ModeAvailability(false, 2, 3, 'no_words'), 'english-a1', 41), ['mode', 'available', 'required', 'reason', 'course', 'lesson_id']],
        'practice_session_active' => [PracticeException::sessionActive(['session_id' => 7, 'mode' => 'pairs', 'course' => 'a', 'lesson_id' => null, 'progress' => ['done' => 1, 'total' => 3]]), ['session_id', 'mode', 'course', 'lesson_id', 'progress']],
        'practice_session_not_found' => [PracticeException::sessionNotFound(), null],
        'practice_session_closed' => [PracticeException::sessionClosed(FinishReason::Expired), ['finish_reason']],
        'element_not_current' => [PracticeException::elementNotCurrent('eAAAAAAAAAA'), ['current']],
        'answer_already_accepted' => [PracticeException::answerAlreadyAccepted(Verdict::Wrong), ['verdict']],
        'pair_already_matched' => [PracticeException::pairAlreadyMatched('lAAAAAAAAAA'), ['left_id']],
        'invalid_answer_shape' => [PracticeException::invalidAnswerShape(PracticeException::EXPECTED_BIJECTION), ['expected']],
        'unknown_element_id' => [PracticeException::unknownElementId(), null],
        'replace_not_allowed' => [PracticeException::replaceNotAllowed(PracticeException::REPLACE_LIMIT), ['reason']],
        'retry_not_available' => [PracticeException::retryNotAvailable(), null],
        'practice_audio_unavailable' => [PracticeException::audioUnavailable(), null],
        'lesson_not_in_course' => [PracticeException::lessonNotInCourse(), null],
        'course_not_found' => [PracticeException::courseNotFound(), null],
        'lesson_not_found' => [PracticeException::lessonNotFound(), null],
    ];

    foreach ($cases as $code => [$exception, $details]) {
        $body = ApiErrorResource::respond($exception->httpStatus(), $exception->errorCode, $exception->getMessage(), $exception->details)->getData(true);

        expect($exception->errorCode)->toBe($code)
            ->and(array_keys($body))->toBe(['error'])
            ->and(array_keys($body['error']))->toBe($details === null ? ['code', 'message'] : ['code', 'message', 'details'])
            ->and($body['error']['code'])->toBe($code);
        if ($details !== null) {
            expect(array_keys($body['error']['details']))->toBe($details);
        }
    }
});

it('puts `details` INSIDE `error` on the wire, with the keys of docs/16 §7.6: the refusals of opening and of reading', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 1);
    [$learner, $course, $lesson] = contractWorld();

    // 422 practice_not_enough_material: {mode, available, required, reason, course, lesson_id}
    expect(contractError(contractOpen($learner, $course->slug, 'sentence', ['lesson_id' => $lesson->id]), 422, 'practice_not_enough_material', ['mode', 'available', 'required', 'reason', 'course', 'lesson_id']))
        ->toEqual(['mode' => 'sentence', 'available' => 1, 'required' => 3, 'reason' => 'no_items', 'course' => 'english-a1', 'lesson_id' => $lesson->id]);

    // 409 practice_session_active: {session_id, mode, course, lesson_id, progress}
    $session = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();
    $answered = contractAnswer($learner, $session, ['text' => 'nonsense'])->assertOk()->json('session');
    expect(contractError(contractOpen($learner, $course->slug, 'collocation'), 409, 'practice_session_active', ['session_id', 'mode', 'course', 'lesson_id', 'progress']))
        ->toEqual(['session_id' => $session['id'], 'mode' => 'collocation', 'course' => 'english-a1', 'lesson_id' => null, 'progress' => ['done' => 1, 'total' => 5]]);

    // 409 practice_mode_disabled: {mode}
    config(['practice.modes.collocation' => false]);
    expect(contractError(contractOpen($learner, $course->slug, 'collocation'), 409, 'practice_mode_disabled', ['mode']))->toEqual(['mode' => 'collocation']);
    config(['practice.modes.collocation' => true]);

    // 410 practice_session_closed: {finish_reason}
    CarbonImmutable::setTestNow('2026-10-31 10:00:01');
    expect(contractError(contractAnswerElement($learner, $session['id'], $answered['current']['id'], ['skip' => true]), 410, 'practice_session_closed', ['finish_reason']))
        ->toEqual(['finish_reason' => 'expired']);

    // No `details` at all where the document has none: the key is absent, not empty.
    contractError(test()->withHeaders(PracticeKit::headers(Learner::factory()->create()))->getJson("/api/twa/practice/sessions/{$session['id']}"), 404, 'practice_session_not_found', null);
    contractError(contractOpen($learner, 'no-such-course', 'collocation'), 404, 'course_not_found', null);
    contractError(contractOpen($learner, $course->slug, 'collocation', ['lesson_id' => 999999]), 404, 'lesson_not_found', null);
    config(['practice.access' => 'off']);
    contractError(contractOpen($learner, $course->slug, 'collocation'), 403, 'practice_unavailable', null);
});

it('puts `details` INSIDE `error` on the wire: the refusals of an answer, and `round_done` at the end of a round of pairs', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    [$learner, $course] = contractWorld();

    $session = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();
    $elements = array_column(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'], 'id');

    // 409 element_not_current: {current} - the element to answer now
    expect(contractError(contractAnswerElement($learner, $session['id'], $elements[2], ['skip' => true]), 409, 'element_not_current', ['current']))
        ->toEqual(['current' => $elements[0]]);

    // 422 invalid_answer_shape: {expected}; 422 unknown_element_id: no details
    expect(contractError(contractAnswerElement($learner, $session['id'], $elements[0], ['option' => 'x']), 422, 'invalid_answer_shape', ['expected']))
        ->toEqual(['expected' => 'text_or_skip']);
    contractError(contractAnswerElement($learner, $session['id'], 'eNoSuchEl00', ['skip' => true]), 422, 'unknown_element_id', null);

    // 409 answer_already_accepted: {verdict}
    contractAnswerElement($learner, $session['id'], $elements[0], ['text' => 'nonsense'])->assertOk();
    expect(contractError(contractAnswerElement($learner, $session['id'], $elements[0], ['skip' => true]), 409, 'answer_already_accepted', ['verdict']))
        ->toEqual(['verdict' => 'wrong']);

    // Pairs: 409 pair_already_matched {left_id}; 422 invalid_answer_shape {expected: left_right}.
    PracticeSession::query()->delete();
    $pairs = contractOpen($learner, $course->slug, 'pairs')->assertCreated()->json();
    $round = PracticeSession::query()->findOrFail($pairs['id'])->snapshot['elements'][0];
    $lefts = array_column($round['left'], 'id');
    $match = fn (string $left): TestResponse => contractAnswerElement($learner, $pairs['id'], $round['id'], ['left_id' => $left, 'right_id' => $round['pairs'][$left]]);

    $match($lefts[0])->assertOk()->assertJsonPath('round_done', false);
    expect(contractError($match($lefts[0]), 409, 'pair_already_matched', ['left_id']))->toEqual(['left_id' => $lefts[0]])
        ->and(contractError(contractAnswerElement($learner, $pairs['id'], $round['id'], ['left_id' => $lefts[1]]), 422, 'invalid_answer_shape', ['expected']))->toEqual(['expected' => 'left_right']);

    // `round_done` is false until the last word of the round is matched, true with it - and a match shows no reveal.
    $match($lefts[1])->assertOk()->assertJsonPath('round_done', false);
    $last = $match($lefts[2])->assertOk();
    expect($last->json('round_done'))->toBeTrue()
        ->and($last->json('reveal'))->toBeNull()
        ->and($last->json('pair'))->toBe(['left_id' => $lefts[2], 'right_id' => $round['pairs'][$lefts[2]]])
        ->and($last->json('session.progress.done'))->toBe(1);
});

it('puts `details` INSIDE `error` on the wire: replace_not_allowed names its reason - not_dictation, not_current or limit', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 8, spares: 3);
    [$learner, $course] = contractWorld();

    $plain = contractOpen($learner, $course->slug, 'collocation')->assertCreated()->json();
    expect(contractError(contractReplace($learner, $plain['id'], $plain['current']['id']), 409, 'replace_not_allowed', ['reason']))->toEqual(['reason' => 'not_dictation']);

    PracticeSession::query()->delete();
    $dictation = contractOpen($learner, $course->slug, 'dictation')->assertCreated()->json();
    $elements = array_column(PracticeSession::query()->findOrFail($dictation['id'])->snapshot['elements'], 'id');
    expect(contractError(contractReplace($learner, $dictation['id'], $elements[3]), 409, 'replace_not_allowed', ['reason']))->toEqual(['reason' => 'not_current']);

    // Two swaps are allowed (`max_replacements`), the third is the limit.
    $one = contractReplace($learner, $dictation['id'], $dictation['current']['id'])->assertOk()->json();
    $two = contractReplace($learner, $dictation['id'], $one['current']['id'])->assertOk()->json();
    expect(contractError(contractReplace($learner, $dictation['id'], $two['current']['id']), 409, 'replace_not_allowed', ['reason']))->toEqual(['reason' => 'limit']);
});

it('keeps the statuses of docs/16 §7.6 for the codes of the practice and of the battle', function (): void {
    $practice = [
        'practice_unavailable' => 403, 'practice_pro_required' => 403, 'practice_mode_disabled' => 409, 'practice_not_enough_material' => 422,
        'practice_session_active' => 409, 'practice_session_not_found' => 404, 'practice_session_closed' => 410, 'element_not_current' => 409,
        'answer_already_accepted' => 409, 'pair_already_matched' => 409, 'invalid_answer_shape' => 422, 'unknown_element_id' => 422,
        'replace_not_allowed' => 409, 'retry_not_available' => 409, 'practice_audio_unavailable' => 503, 'lesson_not_in_course' => 422,
        'course_not_found' => 404, 'lesson_not_found' => 404,
    ];
    foreach ($practice as $code => $status) {
        expect((new PracticeException($code, 'm'))->httpStatus())->toBe($status, $code);
    }

    $battle = [
        'battle_disabled' => 409, 'battle_busy' => 409, 'battle_friend_no_access' => 409, 'battle_material_unavailable' => 422,
        'battle_not_found' => 404, 'battle_not_recipient' => 403, 'battle_not_challenger' => 403, 'battle_closed' => 409,
        'battle_wrong_state' => 409, 'round_not_open' => 409, 'round_closed' => 409,
    ];
    foreach ($battle as $code => $status) {
        expect((new BattleException($code, 'm'))->httpStatus())->toBe($status, $code);
    }
    expect(Battle::query()->count())->toBe(0);
});
