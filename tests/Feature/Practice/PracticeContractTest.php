<?php

declare(strict_types=1);

/*
 * JSON contract of the practice API (docs/16 §7, resources/twa/src/api/types.ts): the exact key structure of every answer, no
 * `data` envelope, `details` only where docs/16 §7.6 describes it, and no key of the snapshot in a response (§2.8, §12).
 * If a test here fails, the TWA client breaks - change the client and docs/04 together.
 */

use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\PairsMode;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Http\Resources\ApiErrorResource;
use App\Models\Battle;
use App\Models\Learner;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\PracticeSession;
use App\Models\Word;
use App\Models\WordTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

it('keeps the contract of the collocation element on the real handler: its exact shape, no secret of the snapshot before the answer, the review only with the answer', function (): void {
    [$learner, $course, $lesson] = contractWorld();
    foreach (PracticeKit::words($lesson, 5, $learner) as $word) {
        PracticeItem::factory()->collocation()->published()->forLesson($lesson)->targeting($word)->create();
    }
    $current = ['id', 'kind', 'text', 'options' => ['*' => ['id', 'text']]];
    $headers = PracticeKit::headers($learner);
    $seen = [];

    $session = contractOpen($learner, $course->slug, 'collocation', ['lesson_id' => $lesson->id])->assertCreated()
        ->assertExactJsonStructure(contractSession($current, lesson: true))->json();
    $seen[] = $session;
    $seen[] = $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->assertExactJsonStructure(contractSession($current, lesson: true))->json();
    $seen[] = $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json();

    // Before the answer neither a key nor a word of what the snapshot knows is out: the whole phrase, the translation, the explanation.
    $stored = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'];
    expect($stored)->toHaveCount(5);
    foreach ($seen as $body) {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        foreach ($stored as $element) {
            expect($json)->not->toContain($element['full_text'])->not->toContain($element['translation']['ru']);
        }
        expect($json)->not->toContain($stored[0]['explanation']['ru']);
    }

    // The review comes with the answer - the review of the element answered, and nothing of the next one.
    $reveal = ['correct_option_ids', 'full_text', 'translation', 'explanation'];
    $first = collect($stored)->firstWhere('id', $session['current']['id']);
    $answer = contractAnswer($learner, $session, ['option_id' => $first['options'][0]['id']])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession($current, lesson: true)]);
    $next = collect($stored)->firstWhere('id', $answer->json('session.current.id'));

    expect($answer->json('reveal.full_text'))->toBe($first['full_text'])
        ->and($answer->json('reveal.correct_option_ids'))->toBe($first['correct_ids'])
        ->and($answer->json('reveal.translation'))->toBe($first['translation']['ru'])
        ->and(json_encode($answer->json('session'), JSON_THROW_ON_ERROR))->not->toContain($next['full_text'])->not->toContain($next['translation']['ru'])->not->toContain($first['full_text']);
    $seen[] = $answer->json('session');

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

/** A learner who has been introduced to three words and five published sentences: for the REAL handler of the sentence mode (18.4). @return array{0: Learner, 1: Course, 2: Lesson} */
function contractSentenceWorld(): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = PracticeKit::words($lesson, 3, $learner);
    foreach (range(1, 5) as $_) {
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
    }

    return [$learner, $course, $lesson];
}

it('shows the sentence mode on the wire: the prompt and the fragments before the answer, the review after it, no key of the snapshot anywhere', function (): void {
    [$learner, $course, $lesson] = contractSentenceWorld();
    $headers = PracticeKit::headers($learner);
    $element = ['id', 'kind', 'prompt', 'fragments' => ['*' => ['id', 'text']]];
    $reveal = ['correct_text', 'alternatives', 'your_text', 'explanation'];

    $session = contractOpen($learner, $course->slug, 'sentence', ['lesson_id' => $lesson->id])->assertCreated()
        ->assertExactJsonStructure(contractSession($element, lesson: true))->json();
    $seen = [
        $session,
        $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->assertExactJsonStructure(contractSession($element, lesson: true))->json(),
        $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json(),
    ];

    // Before the answer: not one of the right sentences or of the explanations of the tasks, and nothing of the review.
    $contents = PracticeItemVersion::query()->get()->map(fn (PracticeItemVersion $v): array => $v->content);
    $json = json_encode($seen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach ($contents as $content) {
        expect($json)->not->toContain($content['display'])->not->toContain($content['explanation']['ru']);
    }
    expect(array_values(array_intersect(contractKeys($seen), ['correct_text', 'alternatives', 'your_text'])))->toBe([]);

    // The fragments are shown in an order that is not a right sentence, so the line as shown is a wrong answer: the review comes back whole.
    $answer = contractAnswer($learner, $session, ['order' => array_column($session['current']['fragments'], 'id')])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession($element, lesson: true)]);
    expect($answer->json('verdict'))->toBe('wrong')
        ->and($answer->json('pair'))->toBeNull()
        ->and($answer->json('reveal.correct_text'))->toBeString()->not->toBe('')
        ->and($answer->json('reveal.alternatives'))->toBeArray()
        ->and($answer->json('reveal.your_text'))->toBeString()
        // the review shows the sentence that was answered, none of the sentences that are still to come
        ->and(array_values(array_intersect(contractKeys($answer->json()), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
    $next = json_encode($answer->json('session'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    expect($next)->not->toContain($answer->json('reveal.correct_text'));

    // "I don't know": the same review without a line of the learner; four more and the session is over, with the summary of its mistakes.
    $session = $answer->json('session');
    $skipped = contractAnswer($learner, $session, ['skip' => true])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession($element, lesson: true)])
        ->assertJsonPath('verdict', 'skipped')
        ->assertJsonPath('reveal.your_text', null);
    $session = $skipped->json('session');
    foreach ([3, 4] as $_) {
        $session = contractAnswer($learner, $session, ['skip' => true])->assertOk()->json('session');
    }
    $last = contractAnswer($learner, $session, ['skip' => true])->assertOk()
        ->assertExactJsonStructure(['verdict', 'first_attempt', 'reveal' => $reveal, 'pair', 'session' => contractSession(null, lesson: true, summary: true)]);

    $mistakes = $last->json('session.summary.mistakes');
    expect($mistakes)->toHaveCount(5)
        ->and(array_column($mistakes, 'kind'))->toBe(array_fill(0, 5, 'sentence'))
        ->and($mistakes[0]['verdict'])->toBe('wrong')
        ->and($mistakes[0]['your_answer'])->toBeString()
        ->and($mistakes[1]['verdict'])->toBe('skipped')
        ->and($mistakes[1]['your_answer'])->toBeNull()
        ->and(array_values(array_intersect(contractKeys($last->json()), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
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

/**
 * A learner who has met twelve words of a lesson ("word1" - "перевод 1"...): the REAL handler of the pairs plays them.
 *
 * @return array{0: Learner, 1: Course, 2: Lesson}
 */
function contractPairsWorld(int $words = 12): array
{
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    for ($i = 1; $i <= $words; $i++) {
        PracticeKit::introduce($learner, Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}"]));
    }

    return [$learner, $course, $lesson];
}

it('keeps the JSON of the pairs on the real handler: the board, a wrong and a right match, the end of a round, the summary - and none of the secrets of the snapshot', function (): void {
    [$learner, $course, $lesson] = contractPairsWorld();
    $board = ['id', 'kind', 'round', 'rounds', 'left' => ['*' => ['id', 'text']], 'right' => ['*' => ['id', 'text']], 'matched', 'mismatches'];
    $match = ['verdict', 'first_attempt', 'reveal', 'pair' => ['left_id', 'right_id'], 'round_done'];
    $seen = [];

    // The board.
    $session = contractOpen($learner, $course->slug, 'pairs', ['lesson_id' => $lesson->id])->assertCreated()
        ->assertExactJsonStructure(contractSession($board, lesson: true))->json();
    $seen[] = $session;
    $rounds = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'];
    $table = $rounds[0]['pairs'];
    $elementId = $rounds[0]['id'];
    [$a, $b, $c, $d] = array_column($session['current']['left'], 'id');
    $try = fn (string $left, string $right): TestResponse => contractAnswerElement($learner, $session['id'], $elementId, ['left_id' => $left, 'right_id' => $right]);

    $seen[] = test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()
        ->assertExactJsonStructure(contractSession($board, lesson: true))->json();
    $seen[] = test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->json();

    // A wrong match: the verdict, the pair that was tried, no reveal, and the partner of the word is not named anywhere.
    $miss = $try($a, $table[$b])->assertOk()->assertExactJsonStructure([...$match, 'session' => contractSession($board, lesson: true)]);
    $seen[] = $miss->json();
    expect($miss->json('verdict'))->toBe('wrong')
        ->and($miss->json('reveal'))->toBeNull()
        ->and($miss->json('pair'))->toBe(['left_id' => $a, 'right_id' => $table[$b]])
        ->and($miss->json('round_done'))->toBeFalse()
        ->and($miss->json('session.current.matched'))->toBe([])
        ->and($miss->json('session.current.mismatches'))->toBe(1);

    // A right one.
    $hit = $try($a, $table[$a])->assertOk()->assertExactJsonStructure([...$match, 'session' => contractSession($board, lesson: true)]);
    $seen[] = $hit->json();
    expect($hit->json('session.current.matched'))->toBe([[$a, $table[$a]]])
        ->and($hit->json('reveal'))->toBeNull();

    // The refusals of a real round, on the wire.
    expect(contractError($try($a, $table[$a]), 409, 'pair_already_matched', ['left_id']))->toEqual(['left_id' => $a])
        ->and(contractError(contractAnswerElement($learner, $session['id'], $elementId, ['left_id' => $b]), 422, 'invalid_answer_shape', ['expected']))->toEqual(['expected' => 'left_right'])
        ->and(contractError($try('lNoSuchWord', $table[$b]), 422, 'unknown_element_id', null))->toBe([]);

    // The end of a round: `round_done`, and the next board in the same response.
    $try($b, $table[$b])->assertOk();
    $try($c, $table[$c])->assertOk();
    $end = $try($d, $table[$d])->assertOk()->assertExactJsonStructure([...$match, 'session' => contractSession($board, lesson: true)]);
    $seen[] = $end->json();
    expect($end->json('round_done'))->toBeTrue()
        ->and($end->json('session.current.id'))->toBe($rounds[1]['id'])
        ->and($end->json('session.current.round'))->toBe(2)
        ->and($end->json('session.progress'))->toBe(['done' => 1, 'total' => 3]);

    // The summary: the mistakes are listed with the right pairing - once the session is over.
    foreach ([1, 2] as $index) {
        foreach ($rounds[$index]['left'] as $left) {
            $last = contractAnswerElement($learner, $session['id'], $rounds[$index]['id'], ['left_id' => $left['id'], 'right_id' => $rounds[$index]['pairs'][$left['id']]])->assertOk();
        }
    }
    $last->assertExactJsonStructure([...$match, 'session' => contractSession(null, lesson: true, summary: true)]);
    $seen[] = $last->json();
    $mistakes = $last->json('session.summary.mistakes');

    expect($mistakes)->toHaveCount(1)
        ->and($mistakes[0]['kind'])->toBe('pairs_round')
        ->and($mistakes[0]['verdict'])->toBe('wrong')
        ->and($mistakes[0]['your_answer'])->toBeNull()
        ->and($mistakes[0]['label'])->toMatch('/^word\d+ — перевод \d+$/u')
        ->and($last->json('session.summary.mismatches'))->toBe(1);

    $seen[] = test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()
        ->assertExactJsonStructure(contractSession(null, lesson: true, summary: true))->json();

    foreach ($seen as $body) {
        expect(array_values(array_intersect(contractKeys($body), CONTRACT_FORBIDDEN_PRACTICE_KEYS)))->toBe([]);
    }
});

it('hands out ids that say nothing, and answers every wrong guess with the same «wrong» and nothing more', function (): void {
    [$learner, $course] = contractPairsWorld();
    $session = contractOpen($learner, $course->slug, 'pairs')->assertCreated()->json();
    $round = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'][0];
    $a = $session['current']['left'][0]['id'];

    // The ids of the board are random: a prefix and ten characters, no word id, no order of the pairs in them.
    $ids = array_merge(array_column($session['current']['left'], 'id'), array_column($session['current']['right'], 'id'));
    expect($session['current']['id'])->toMatch('/^e[A-Za-z0-9]{10}$/')
        ->and(array_unique($ids))->toHaveCount(8);
    foreach ($ids as $id) {
        expect($id)->toMatch('/^[lr][A-Za-z0-9]{10}$/');
    }

    // Three cards are wrong for the word: three times «wrong», the same body, and the board has no new fact.
    $wrongCards = array_values(array_diff(array_column($session['current']['right'], 'id'), [$round['pairs'][$a]]));
    foreach ($wrongCards as $index => $card) {
        $answer = contractAnswerElement($learner, $session['id'], $round['id'], ['left_id' => $a, 'right_id' => $card])->assertOk();

        expect(array_keys($answer->json()))->toBe(['verdict', 'first_attempt', 'reveal', 'pair', 'round_done', 'session'])
            ->and($answer->json('verdict'))->toBe('wrong')
            ->and($answer->json('first_attempt'))->toBe($index === 0)
            ->and($answer->json('reveal'))->toBeNull()
            ->and($answer->json('pair'))->toBe(['left_id' => $a, 'right_id' => $card])
            ->and($answer->json('session.current.matched'))->toBe([])
            ->and($answer->json('session.current.left'))->toBe($session['current']['left'])
            ->and($answer->json('session.current.right'))->toBe($session['current']['right']);
    }
    expect($wrongCards)->toHaveCount(3);
});

it('shows a round of a battle the way it shows a solo one - the same board in the language of the viewer, none of the secrets, nothing matched', function (): void {
    [$course, $lesson] = PracticeKit::course();
    foreach (range(1, 20) as $i) {
        $word = Word::factory()->for($lesson)->create(['word' => "word{$i}", 'translation' => "перевод {$i}"]);
        if ($i % 2 === 0) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id, 'translation' => "tarjima {$i}"]);
        }
    }
    $snapshot = app(PracticeSnapshotBuilder::class)->build(PracticeMode::Pairs, new MaterialScope($course, $lesson), null, LocaleSet::of('ru', 'uz'), 5, true, str_repeat('b', 32));
    $mode = app(PairsMode::class);

    expect($snapshot->count())->toBe(5);
    foreach ($snapshot->elements as $index => $element) {
        $ru = $mode->project($element, 'ru', ElementState::open());
        $uz = $mode->project($element, 'uz', ElementState::open());

        expect(array_keys($ru))->toBe(['id', 'kind', 'round', 'rounds', 'left', 'right', 'matched', 'mismatches'])
            ->and($ru['kind'])->toBe('pairs_round')
            ->and([$ru['round'], $ru['rounds']])->toBe([$index + 1, 5])
            ->and([$ru['matched'], $ru['mismatches']])->toBe([[], 0])
            ->and($uz['left'])->toBe($ru['left'])                                          // the English side is one for both players
            ->and(array_column($uz['right'], 'id'))->toBe(array_column($ru['right'], 'id'))   // the same cards in the same places
            ->and(array_values(array_intersect(contractKeys($ru), [...CONTRACT_FORBIDDEN_PRACTICE_KEYS, 'battle'])))->toBe([])
            ->and(array_values(array_intersect(contractKeys($uz), [...CONTRACT_FORBIDDEN_PRACTICE_KEYS, 'battle'])))->toBe([]);
        foreach ($ru['right'] as $card) {
            expect($card['text'])->toStartWith('перевод ');
        }
        foreach ($uz['right'] as $card) {
            expect($card['text'])->toMatch('/^(tarjima|перевод) \d+$/u');   // uz where there is a uz text, the ru fallback where there is none
        }
    }
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
