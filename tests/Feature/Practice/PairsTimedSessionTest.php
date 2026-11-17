<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\PracticeAttempt;
use App\Models\PracticeRecord;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PairsTimedKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §5.5a, §6.1, §6.4, §14.2 (B3): "Pairs against the clock". The clock, the score and the penalty are the server's: nothing is
 * answered before `POST /start`, nothing after `deadline + grace`, a wrong match moves the deadline, a right one is a point, the
 * score is a column that only an answer changes, the personal best is written in the transaction that finishes the game.
 */

uses(RefreshDatabase::class);

const PAIRS_TIMED_T0 = '2026-11-18 10:00:00';

/** What a session of this mode must never show a client, at any depth. */
const PAIRS_TIMED_FORBIDDEN_KEYS = ['pairs', 'word_id', 'item_id', 'version_id', 'correct_ids', 'accepted', 'expected', 'pool', 'snapshot', 'state'];

beforeEach(function (): void {
    CarbonImmutable::setTestNow(PAIRS_TIMED_T0);
    PracticeKit::openAccess();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The clock at `$ms` milliseconds after the start of the test. */
function pairsTimedAt(int $ms): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse(PAIRS_TIMED_T0)->addMilliseconds($ms));
}

/** @param array<string, mixed> $body */
function pairsTimedCall(Learner $learner, string $method, string $uri, array $body = []): TestResponse
{
    PracticeKit::resetLimiters($learner);

    return test()->withHeaders(PracticeKit::headers($learner))->json($method, "/api/twa/practice/{$uri}", $body);
}

/** @param array<string, mixed> $extra */
function pairsTimedOpen(Learner $learner, Course $course, array $extra = []): TestResponse
{
    return pairsTimedCall($learner, 'POST', 'sessions', ['mode' => 'pairs_timed', 'course' => $course->slug, ...$extra]);
}

function pairsTimedStart(Learner $learner, int $id): TestResponse
{
    return pairsTimedCall($learner, 'POST', "sessions/{$id}/start");
}

/** @return array<string, string> the right table of an element (a test may read the snapshot) */
function pairsTimedTable(int $sessionId, string $elementId): array
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element['pairs'];
        }
    }

    throw new RuntimeException('No such element.');
}

function pairsTimedAnswer(Learner $learner, int $id, string $elementId, string $left, string $right, ?string $requestId = null): TestResponse
{
    return pairsTimedCall($learner, 'POST', "sessions/{$id}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => ['left_id' => $left, 'right_id' => $right],
    ]);
}

/** The first word of the current round that is not matched yet. @param array<string, mixed> $session */
function pairsTimedOpenLeft(array $session): string
{
    $matched = array_column($session['current']['matched'], 0);

    return array_values(array_diff(array_column($session['current']['left'], 'id'), $matched))[0];
}

/**
 * Matches `$pairs` pairs right, one by one; returns the session after the last answer.
 *
 * @param  array<string, mixed>  $session
 * @return array<string, mixed>
 */
function pairsTimedRight(Learner $learner, array $session, int $pairs): array
{
    for ($i = 0; $i < $pairs; $i++) {
        $left = pairsTimedOpenLeft($session);
        $table = pairsTimedTable($session['id'], $session['current']['id']);
        $session = pairsTimedAnswer($learner, $session['id'], $session['current']['id'], $left, $table[$left])->assertOk()->json('session');
    }

    return $session;
}

/** A wrong match: the first open word against a card that is not its own. @param array<string, mixed> $session */
function pairsTimedWrong(Learner $learner, array $session, ?string $requestId = null): TestResponse
{
    $left = pairsTimedOpenLeft($session);
    $table = pairsTimedTable($session['id'], $session['current']['id']);
    $wrong = array_values(array_diff(array_column($session['current']['right'], 'id'), [$table[$left]], array_column($session['current']['matched'], 1)))[0];

    return pairsTimedAnswer($learner, $session['id'], $session['current']['id'], $left, $wrong, $requestId);
}

/**
 * A learner who has met 8 words of a lesson, and a started game of `pairs_timed` for them, at T0.
 *
 * @return array{0: Learner, 1: Course, 2: array<string, mixed>} the learner, the course, the session as the client sees it after `start`
 */
function pairsTimedStarted(int $words = 8): array
{
    [$learner, $course] = PairsTimedKit::world($words);
    $id = (int) pairsTimedOpen($learner, $course)->assertCreated()->json('id');

    return [$learner, $course, pairsTimedStart($learner, $id)->assertOk()->json()];
}

/** @return array<array-key, mixed> every key at any depth */
function pairsTimedKeys(mixed $value): array
{
    $keys = [];
    foreach (is_array($value) ? $value : [] as $key => $item) {
        $keys[] = $key;
        array_push($keys, ...pairsTimedKeys($item));
    }

    return $keys;
}

it('opens a game without a clock: no deadline, score 0, ten rounds of four, nothing that gives the pairs away', function (): void {
    [$learner, $course] = PairsTimedKit::world(8);

    $opened = pairsTimedOpen($learner, $course)->assertCreated();

    $opened->assertJsonPath('mode', 'pairs_timed')
        ->assertJsonPath('timed', ['seconds' => 60, 'deadline_at' => null, 'started' => false, 'score' => 0, 'penalty_ms' => 2000])
        ->assertJsonPath('progress', ['done' => 0, 'total' => 10])
        ->assertJsonPath('current.rounds', 10)
        ->assertJsonPath('current.round', 1);
    expect($opened->json('current.left'))->toHaveCount(4)->and($opened->json('current.right'))->toHaveCount(4)
        ->and(array_intersect(pairsTimedKeys($opened->json()), PAIRS_TIMED_FORBIDDEN_KEYS))->toBe([]);
    $row = PracticeSession::query()->sole();
    expect($row->deadline_at)->toBeNull()->and($row->score)->toBe(0)->and($row->mode)->toBe(PracticeMode::PairsTimed);
});

it('refuses an answer before the clock is started', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $session = pairsTimedOpen($learner, $course)->assertCreated()->json();
    $left = pairsTimedOpenLeft($session);

    pairsTimedAnswer($learner, $session['id'], $session['current']['id'], $left, pairsTimedTable($session['id'], $session['current']['id'])[$left])
        ->assertStatus(409)->assertJsonPath('error.code', 'practice_not_started');

    expect(PracticeSession::query()->sole()->score)->toBe(0)->and(PracticeAttempt::query()->count())->toBe(0);
});

it('starts the clock once: deadline = now + 60 s, the same one for every repeat and every tab', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $id = (int) pairsTimedOpen($learner, $course)->assertCreated()->json('id');

    $first = pairsTimedStart($learner, $id)->assertOk();
    $deadline = $first->json('timed.deadline_at');
    expect($deadline)->toBe('2026-11-18T10:01:00.000Z')
        ->and($first->json('timed.started'))->toBeTrue()
        ->and($first->json('server_now'))->toBe('2026-11-18T10:00:00.000Z');

    pairsTimedAt(20_000);
    pairsTimedStart($learner, $id)->assertOk()->assertJsonPath('timed.deadline_at', $deadline)->assertJsonPath('server_now', '2026-11-18T10:00:20.000Z');
    expect(PracticeSession::query()->sole()->deadline_at->format('Y-m-d H:i:s.v'))->toBe('2026-11-18 10:01:00.000');
});

it('refuses to start what has no clock, what is not yours, and what is over', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $stranger = Learner::factory()->create();

    $pairs = pairsTimedCall($learner, 'POST', 'sessions', ['mode' => 'pairs', 'course' => $course->slug])->assertCreated()->json('id');
    pairsTimedStart($learner, $pairs)->assertStatus(409)->assertJsonPath('error.code', 'practice_not_timed');

    $timed = (int) pairsTimedOpen($learner, $course, ['replace_active' => true])->assertCreated()->json('id');
    pairsTimedStart($stranger, $timed)->assertNotFound();
    pairsTimedStart($learner, 999_999)->assertNotFound();

    pairsTimedStart($learner, $timed)->assertOk();
    PracticeSession::query()->whereKey($timed)->update(['status' => 'finished', 'active_learner_id' => null, 'finish_reason' => 'replaced']);
    pairsTimedStart($learner, $timed)->assertStatus(410)->assertJsonPath('error.code', 'practice_session_closed');
});

it('scores a right match, and takes two seconds off the clock for a wrong one - the score does not move', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();

    pairsTimedAt(1_000);
    $right = pairsTimedRight($learner, $session, 1);
    expect($right['timed']['score'])->toBe(1)->and($right['timed']['deadline_at'])->toBe('2026-11-18T10:01:00.000Z');

    pairsTimedAt(2_000);
    $wrong = pairsTimedWrong($learner, $right)->assertOk();
    $wrong->assertJsonPath('verdict', 'wrong')->assertJsonPath('penalty_ms', 2000)->assertJsonPath('reveal', null)
        ->assertJsonPath('session.timed.score', 1)
        ->assertJsonPath('session.timed.deadline_at', '2026-11-18T10:00:58.000Z')
        ->assertJsonPath('session.counters.mismatches', 1);
    expect(PracticeSession::query()->sole())->score->toBe(1)->mismatches->toBe(1);

    // A right one has no penalty field set.
    pairsTimedRight($learner, $wrong->json('session'), 1);
    expect(PracticeSession::query()->sole())->score->toBe(2);
});

it('does not charge the same wrong answer twice when its request is repeated', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    $requestId = (string) Str::uuid();

    $first = pairsTimedWrong($learner, $session, $requestId)->assertOk();
    pairsTimedAt(500);
    $again = pairsTimedWrong($learner, $session, $requestId)->assertOk();

    expect($again->json('verdict'))->toBe('wrong')
        ->and($again->json('penalty_ms'))->toBe(2000)
        ->and($again->json('session.timed.deadline_at'))->toBe($first->json('session.timed.deadline_at'))
        ->and(PracticeSession::query()->sole()->mismatches)->toBe(1)
        ->and(PracticeAttempt::query()->count())->toBe(1);

    // Another wrong answer (another request) is another penalty: two tabs, two mistakes.
    pairsTimedWrong($learner, $first->json('session'))->assertOk()->assertJsonPath('session.timed.deadline_at', '2026-11-18T10:00:56.000Z');
});

it('ignores a score that a client sends, and gives nothing away with a wrong answer', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    $left = pairsTimedOpenLeft($session);
    $table = pairsTimedTable($session['id'], $session['current']['id']);

    $response = pairsTimedCall($learner, 'POST', "sessions/{$session['id']}/answer", [
        'request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'score' => 99, 'deadline_at' => '2030-01-01T00:00:00Z',
        'answer' => ['left_id' => $left, 'right_id' => $table[$left], 'score' => 99],
    ])->assertOk();

    expect($response->json('session.timed.score'))->toBe(1)
        ->and(PracticeSession::query()->sole()->score)->toBe(1)
        ->and(PracticeSession::query()->sole()->deadline_at->format('c'))->toBe('2026-11-18T10:01:00+00:00');

    // A wrong match says "wrong" and nothing more - the same as in the usual pairs; no table, no hint of which card is right.
    $wrong = pairsTimedWrong($learner, $response->json('session'))->assertOk();
    expect($wrong->json('reveal'))->toBeNull()
        ->and(array_intersect(pairsTimedKeys($wrong->json()), PAIRS_TIMED_FORBIDDEN_KEYS))->toBe([])
        ->and(array_keys($wrong->json()))->toBe(['verdict', 'first_attempt', 'reveal', 'pair', 'round_done', 'penalty_ms', 'session']);
});

it('takes an answer up to 1.5 s after the deadline, and closes the game with the score on the first one after that', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    $session = pairsTimedRight($learner, $session, 3);

    pairsTimedAt(60_000 + 1_400);
    $session = pairsTimedRight($learner, $session, 1);
    expect($session['timed']['score'])->toBe(4)->and($session['status'])->toBe('active');

    pairsTimedAt(60_000 + 1_600);
    $left = pairsTimedOpenLeft($session);
    $table = pairsTimedTable($session['id'], $session['current']['id']);
    $late = pairsTimedAnswer($learner, $session['id'], $session['current']['id'], $left, $table[$left]);

    $late->assertStatus(409)->assertJsonPath('error.code', 'practice_time_over')->assertJsonPath('error.details', ['score' => 4]);
    $row = PracticeSession::query()->sole();
    expect($row->status->value)->toBe('finished')->and($row->finish_reason->value)->toBe('time_over')->and($row->score)->toBe(4)
        ->and($row->active_learner_id)->toBeNull()
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);

    // The same answer once more: the game is over (410), no second event.
    pairsTimedAnswer($learner, $session['id'], $session['current']['id'], $left, $table[$left])->assertStatus(410);
    expect(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('closes a game past its clock lazily on a read, once, and shows the summary with the score', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    pairsTimedRight($learner, $session, 5);

    pairsTimedAt(60_000 + 1_000); // inside the grace: still on
    pairsTimedCall($learner, 'GET', "sessions/{$session['id']}")->assertOk()->assertJsonPath('status', 'active');

    pairsTimedAt(60_000 + 2_000);
    $shown = pairsTimedCall($learner, 'GET', "sessions/{$session['id']}")->assertOk();
    pairsTimedCall($learner, 'GET', "sessions/{$session['id']}")->assertOk();

    $shown->assertJsonPath('status', 'finished')->assertJsonPath('current', null)
        ->assertJsonPath('summary.finish_reason', 'time_over')
        ->assertJsonPath('summary.score', 5)
        ->assertJsonPath('summary.best_score', 5)
        ->assertJsonPath('summary.is_record', true)
        ->assertJsonPath('summary.retry_available', false)
        ->assertJsonPath('summary.mistakes', [])
        ->assertJsonPath('summary.typos', 0)
        ->assertJsonPath('summary.hints', 0)
        ->assertJsonPath('summary.mismatches', 0)
        ->assertJsonPath('summary.duration_ms', 60_000)
        ->assertJsonPath('timed.score', 5);
    expect(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
    $event = ProductEvent::query()->where('name', 'practice.session_finished')->sole();
    expect($event->props)->toMatchArray(['mode' => 'pairs_timed', 'reason' => 'time_over', 'score' => 5, 'deadline_at' => '2026-11-18T10:01:00.000Z']);
});

it('lets the scheduler close a timed game that ran out as time_over, and leaves one inside its grace', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    pairsTimedRight($learner, $session, 2);

    pairsTimedAt(60_000 + 1_000);
    Artisan::call('practice:close-expired');
    expect(PracticeSession::query()->sole()->status->value)->toBe('active');

    pairsTimedAt(60_000 + 1_600);
    Artisan::call('practice:close-expired');
    Artisan::call('practice:close-expired'); // a repeat changes nothing

    $row = PracticeSession::query()->sole();
    expect($row->finish_reason->value)->toBe('time_over')->and($row->score)->toBe(2)->and($row->active_learner_id)->toBeNull()
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1)
        ->and(PracticeRecord::query()->sole()->best_score)->toBe(2);
});

it('opens a new game over one whose clock ran out and was not closed yet, with the record of the old one kept', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    pairsTimedRight($learner, $session, 3);

    pairsTimedAt(90_000);
    $new = pairsTimedOpen($learner, $course)->assertCreated();

    expect($new->json('id'))->not->toBe($session['id'])
        ->and(PracticeSession::query()->find($session['id'])->finish_reason->value)->toBe('time_over')
        ->and(PracticeRecord::query()->sole()->best_score)->toBe(3);
});

it('completes the game when all ten rounds are matched before the clock, with the score of every pair', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();

    $session = pairsTimedRight($learner, $session, 40);

    expect($session['status'])->toBe('finished')
        ->and($session['summary'])->toMatchArray(['finish_reason' => 'completed', 'score' => 40, 'answered' => 10, 'total' => 10, 'is_record' => true, 'best_score' => 40, 'retry_available' => false])
        ->and($session['timed']['score'])->toBe(40)
        ->and(PracticeSession::query()->sole())->finish_reason->toBe(\App\Domain\Practice\Enums\FinishReason::Completed)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('keeps the best score: the first game writes it, a lower one does not, a higher one does, a game that was not played out writes nothing', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $play = function (int $pairs, int $at) use ($learner, $course): int {
        pairsTimedAt($at);
        $id = (int) pairsTimedOpen($learner, $course)->assertCreated()->json('id');
        $session = pairsTimedStart($learner, $id)->assertOk()->json();
        if ($pairs > 0) {
            pairsTimedRight($learner, $session, $pairs);
        }
        pairsTimedAt($at + 70_000);
        pairsTimedCall($learner, 'GET', "sessions/{$id}")->assertOk()->assertJsonPath('summary.finish_reason', 'time_over');

        return $id;
    };
    $summary = fn (int $id): array => pairsTimedCall($learner, 'GET', "sessions/{$id}")->json('summary');

    $first = $play(3, 0);
    expect(PracticeRecord::query()->sole())->best_score->toBe(3)->best_session_id->toBe($first)
        ->and($summary($first))->toMatchArray(['is_record' => true, 'best_score' => 3]);

    $lower = $play(2, 100_000);
    expect(PracticeRecord::query()->sole())->best_score->toBe(3)->best_session_id->toBe($first)
        ->and($summary($lower))->toMatchArray(['score' => 2, 'is_record' => false, 'best_score' => 3]);

    $same = $play(3, 200_000);
    expect(PracticeRecord::query()->sole()->best_session_id)->toBe($first)->and($summary($same)['is_record'])->toBeFalse();

    $higher = $play(5, 300_000);
    expect(PracticeRecord::query()->sole())->best_score->toBe(5)->best_session_id->toBe($higher)
        ->and($summary($higher))->toMatchArray(['is_record' => true, 'best_score' => 5]);

    $nothing = $play(0, 400_000);
    expect(PracticeRecord::query()->sole()->best_score)->toBe(5)->and($summary($nothing)['is_record'])->toBeFalse();

    // A game that is replaced, and one that expires after a day, are not played out: they leave the record alone.
    pairsTimedAt(500_000);
    $dropped = pairsTimedOpen($learner, $course)->assertCreated()->json();
    $dropped = pairsTimedStart($learner, $dropped['id'])->assertOk()->json();
    $dropped = pairsTimedRight($learner, $dropped, 9);
    pairsTimedOpen($learner, $course, ['replace_active' => true])->assertCreated();
    expect(PracticeSession::query()->find($dropped['id'])->finish_reason->value)->toBe('replaced')
        ->and(PracticeRecord::query()->sole()->best_score)->toBe(5);

    $current = PracticeSession::query()->where('status', 'active')->sole();
    $current = pairsTimedStart($learner, $current->id)->assertOk()->json();
    pairsTimedRight($learner, $current, 9);
    pairsTimedAt(500_000 + 25 * 3_600_000);
    pairsTimedCall($learner, 'GET', "sessions/{$current['id']}")->assertOk()->assertJsonPath('summary.finish_reason', 'expired');
    expect(PracticeRecord::query()->sole()->best_score)->toBe(5);
});

it('shows the record on the hub card of the mode, and only there', function (): void {
    [$learner, $course] = PairsTimedKit::world();

    $none = pairsTimedCall($learner, 'GET', "hub?course={$course->slug}")->assertOk()->json('modes');
    expect(array_column($none, 'record', 'mode')['pairs_timed'])->toBeNull();

    PracticeRecord::factory()->create(['learner_id' => $learner->id, 'best_score' => 28, 'achieved_at' => '2026-11-17 09:00:00']);
    $modes = pairsTimedCall($learner, 'GET', "hub?course={$course->slug}")->assertOk()->json('modes');
    $timed = collect($modes)->firstWhere('mode', 'pairs_timed');
    expect($timed)->toMatchArray(['state' => 'available', 'required' => 8, 'estimate_seconds' => 60, 'record' => ['score' => 28, 'achieved_at' => '2026-11-17T09:00:00.000Z']])
        ->and(collect($modes)->filter(fn (array $m): bool => array_key_exists('record', $m))->count())->toBe(1);
});

it('counts a timed game as a session of the day for a Free learner, and refuses the one over the limit', function (): void {
    PracticeKit::limits(2, null);
    [$learner, $course] = PairsTimedKit::world();

    pairsTimedOpen($learner, $course)->assertCreated()->assertJsonPath('limits.sessions.used', 1);
    pairsTimedOpen($learner, $course, ['replace_active' => true])->assertCreated()->assertJsonPath('limits.sessions.used', 2);
    pairsTimedOpen($learner, $course, ['replace_active' => true])->assertForbidden()->assertJsonPath('error.code', 'practice_limit_reached');
    expect(PracticeSession::query()->count())->toBe(2);
});

it('hides the mode when its switch is off and refuses to open it, while a game on goes on', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    config(['practice.modes.pairs_timed' => false]);

    $hub = collect(pairsTimedCall($learner, 'GET', "hub?course={$course->slug}")->assertOk()->json('modes'))->firstWhere('mode', 'pairs_timed');
    expect($hub['state'])->toBe('disabled');

    pairsTimedRight($learner, $session, 1); // the game on goes on
    pairsTimedOpen($learner, $course, ['replace_active' => true])->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled');
});

it('does not offer the repeat of a timed game, and refuses it', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    pairsTimedRight($learner, $session, 1);
    pairsTimedAt(70_000);
    pairsTimedCall($learner, 'GET', "sessions/{$session['id']}")->assertOk()->assertJsonPath('summary.retry_available', false);

    pairsTimedCall($learner, 'POST', 'sessions', ['retry_of' => $session['id'], 'source' => 'retry'])
        ->assertStatus(409)->assertJsonPath('error.code', 'retry_not_available');
});

it('settles two finishes of the same game as one: the record is written once and one event is raised', function (): void {
    [$learner, $course, $session] = pairsTimedStarted();
    pairsTimedRight($learner, $session, 4);
    pairsTimedAt(70_000);
    $service = app(PracticeSessionService::class);

    $first = $service->find($learner, $session['id']);
    $second = $service->find($learner, $session['id']);

    expect($first->finish_reason->value)->toBe('time_over')->and($second->finish_reason->value)->toBe('time_over')
        ->and(PracticeRecord::query()->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'practice.session_finished')->count())->toBe(1);
});

it('stays within the statement budgets: start 4, an answer 6, the last one with a record 9, the summary of a finished game 4', function (): void {
    [$learner, $course] = PairsTimedKit::world();
    $id = (int) pairsTimedOpen($learner, $course)->assertCreated()->json('id');
    PracticeKit::resetLimiters($learner);

    $start = QueryLog::during(fn () => pairsTimedStart($learner, $id)->assertOk());
    $session = pairsTimedCall($learner, 'GET', "sessions/{$id}")->json();

    $left = pairsTimedOpenLeft($session);
    $table = pairsTimedTable($id, $session['current']['id']);
    $answer = QueryLog::during(fn () => pairsTimedAnswer($learner, $id, $session['current']['id'], $left, $table[$left])->assertOk());

    $session = pairsTimedCall($learner, 'GET', "sessions/{$id}")->json();
    $session = pairsTimedRight($learner, $session, 38);
    $left = pairsTimedOpenLeft($session);
    $table = pairsTimedTable($id, $session['current']['id']);
    $last = QueryLog::during(fn () => pairsTimedAnswer($learner, $id, $session['current']['id'], $left, $table[$left])->assertOk());

    $show = QueryLog::during(fn () => pairsTimedCall($learner, 'GET', "sessions/{$id}")->assertOk());

    expect(count($start))->toBeLessThanOrEqual(4, implode("\n", $start))
        ->and(count($answer))->toBeLessThanOrEqual(6, implode("\n", $answer))
        ->and(count($last))->toBeLessThanOrEqual(9, implode("\n", $last))
        ->and(count($show))->toBeLessThanOrEqual(4, implode("\n", $show));
});
