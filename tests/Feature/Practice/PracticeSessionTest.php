<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\FinishReason;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\SessionStatus;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeAttempt;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\PracticeKit;

/*
 * The life cycle of a solo session (docs/16 §6) on the test handler (FakeEchoMode): open, one active session, resume, expiry,
 * retry, the summary, the checks of an answer, the idempotency of `request_id`, the swap of a dictation. The modes themselves
 * (18.2-18.5) have tests of their own.
 */

uses(RefreshDatabase::class);

const PS_NOW = '2026-10-30 10:00:00';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(PS_NOW);
    PracticeKit::openAccess();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    // The race test hangs a listener and a global scope on the model: boot it afresh for the next test.
    PracticeSession::flushEventListeners();
    PracticeSession::clearBootedModels();
});

/** @return array{0: Learner, 1: Course, 2: Lesson} */
function psWorld(string $locale = 'ru'): array
{
    [$course, $lesson] = PracticeKit::course();

    return [Learner::factory()->create(['interface_locale' => $locale]), $course, $lesson];
}

/** @param array<string, mixed> $body */
function psOpen(object $test, Learner $learner, string $course, string $mode = 'collocation', array $body = []): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/practice/sessions', ['mode' => $mode, 'course' => $course, ...$body]);
}

/** @param array<string, mixed> $answer */
function psAnswer(object $test, Learner $learner, int $sessionId, string $elementId, array $answer, ?string $requestId = null, ?int $timeSpentMs = null): TestResponse
{
    $body = ['request_id' => $requestId ?? (string) Str::uuid(), 'element_id' => $elementId, 'answer' => $answer];
    if ($timeSpentMs !== null) {
        $body['time_spent_ms'] = $timeSpentMs;
    }

    return $test->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$sessionId}/answer", $body);
}

function psGet(object $test, Learner $learner, int $sessionId): TestResponse
{
    return $test->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/sessions/{$sessionId}");
}

/** What the test handler expects as the answer to an element (read from the snapshot - a test may). */
function psExpected(int $sessionId, string $elementId): string
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element['expected'];
        }
    }

    throw new RuntimeException('No such element.');
}

/** Answers the current element of the session as the response `$session` shows it; returns the answer response. */
function psAnswerCurrent(object $test, Learner $learner, array $session, string $how = 'correct'): TestResponse
{
    $elementId = $session['current']['id'];
    $answer = match ($how) {
        'correct' => ['text' => psExpected($session['id'], $elementId)],
        'wrong' => ['text' => 'nonsense'],
        'typo' => ['text' => 'almost'],
        'skip' => ['skip' => true],
    };

    return psAnswer($test, $learner, $session['id'], $elementId, $answer);
}

function psEvents(string $name): int
{
    return ProductEvent::query()->where('name', $name)->count();
}

it('opens a session: 201 with the first element only, the progress, the counters and the clock', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 8);
    [$learner, $course, $lesson] = psWorld();

    $response = psOpen($this, $learner, $course->slug, body: ['lesson_id' => $lesson->id, 'source' => 'after_training'])->assertCreated();

    $response->assertJsonPath('status', 'active')
        ->assertJsonPath('mode', 'collocation')
        ->assertJsonPath('source', 'after_training')
        ->assertJsonPath('retry_of', null)
        ->assertJsonPath('course', ['slug' => 'english-a1', 'title' => 'Английский A1'])
        ->assertJsonPath('lesson', ['id' => $lesson->id, 'stage' => 1, 'number' => 1, 'title' => 'Еда'])
        ->assertJsonPath('progress', ['done' => 0, 'total' => 5])
        ->assertJsonPath('counters', ['correct_first' => 0, 'hints' => 0, 'mismatches' => 0])
        ->assertJsonPath('expires_at', '2026-10-31T10:00:00.000Z')
        ->assertJsonPath('server_now', '2026-10-30T10:00:00.000Z')
        ->assertJsonPath('summary', null)
        ->assertJsonPath('current.kind', 'collocation');

    $row = PracticeSession::query()->findOrFail($response->json('id'));
    expect($row->learner_id)->toBe($learner->id)
        ->and($row->active_learner_id)->toBe($learner->id)
        ->and($row->status)->toBe(SessionStatus::Active)
        ->and($row->locale)->toBe('ru')
        ->and($row->total)->toBe(5)
        ->and($row->started_at->toDateTimeString())->toBe(PS_NOW)
        ->and($row->state['current'])->toBe(0)
        ->and($row->snapshot['elements'])->toHaveCount(5)
        ->and($row->snapshot['course_slug'])->toBe('english-a1')
        ->and(psEvents('practice.session_started'))->toBe(1);

    $event = ProductEvent::query()->where('name', 'practice.session_started')->firstOrFail();
    expect($event->learner_id)->toBe($learner->id)
        ->and($event->props)->toMatchArray(['session_id' => $row->id, 'mode' => 'collocation', 'course_id' => $course->id, 'lesson_id' => $lesson->id, 'source' => 'after_training', 'total' => 5, 'retry' => false]);
});

it('opens a session of a course without a lesson, in the language of the learner', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld('uz');

    $response = psOpen($this, $learner, $course->slug)->assertCreated();

    $response->assertJsonPath('course.title', 'Ingliz tili A1')->assertJsonPath('lesson', null)->assertJsonPath('source', 'hub');
    expect(PracticeSession::query()->findOrFail($response->json('id'))->locale)->toBe('uz');
});

it('takes the size from the config and never opens a session shorter than the minimum', function (): void {
    [$learner, $course] = psWorld();

    PracticeKit::fake(PracticeMode::Collocation, candidates: 4);
    psOpen($this, $learner, $course->slug)->assertCreated()->assertJsonPath('progress.total', 4);

    config(['practice.solo.items.size' => 3]);
    PracticeSession::query()->delete();
    psOpen($this, $learner, $course->slug)->assertCreated()->assertJsonPath('progress.total', 3);

    // Pairs: the size is the number of rounds.
    PracticeSession::query()->delete();
    PracticeKit::fake(PracticeMode::Pairs, candidates: 12);
    psOpen($this, $learner, $course->slug, 'pairs')->assertCreated()->assertJsonPath('progress.total', 3)->assertJsonPath('current.kind', 'pairs_round');
});

it('answers 422 practice_not_enough_material with the numbers when the material is too small, and writes no session', function (): void {
    [$learner, $course, $lesson] = psWorld();

    // A skeleton mode (18.1) has no material at all.
    psOpen($this, $learner, $course->slug, 'dictation', ['lesson_id' => $lesson->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'practice_not_enough_material')
        ->assertJsonPath('error.details', ['mode' => 'dictation', 'available' => 0, 'required' => 3, 'reason' => null, 'course' => 'english-a1', 'lesson_id' => $lesson->id]);

    PracticeKit::fake(PracticeMode::Sentence, candidates: 2);
    psOpen($this, $learner, $course->slug, 'sentence')
        ->assertStatus(422)
        ->assertJsonPath('error.details', ['mode' => 'sentence', 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'course' => 'english-a1', 'lesson_id' => null]);

    expect(PracticeSession::query()->count())->toBe(0);
    $event = ProductEvent::query()->where('name', 'practice.not_enough_material')->orderByDesc('id')->firstOrFail();
    expect(psEvents('practice.not_enough_material'))->toBe(2)
        ->and($event->props)->toMatchArray(['mode' => 'sentence', 'course_id' => $course->id, 'lesson_id' => null, 'available' => 2, 'required' => 3, 'reason' => 'no_items', 'battle' => false]);
});

it('answers 404 for a course the learner cannot open and for a lesson that is not found, 422 for a lesson of another course', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $draft = Course::factory()->create(['slug' => 'draft']);
    $otherDeck = Course::factory()->deckOf(Learner::factory()->create()->id)->create(['slug' => 'their-deck']);
    $other = Course::factory()->published()->create(['slug' => 'other']);
    $foreignLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $other->id]))->create();
    $secretLesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $otherDeck->id]))->create();

    psOpen($this, $learner, 'draft')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    psOpen($this, $learner, 'their-deck')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    psOpen($this, $learner, 'no-such-course')->assertNotFound()->assertJsonPath('error.code', 'course_not_found');
    psOpen($this, $learner, $course->slug, body: ['lesson_id' => 999999])->assertNotFound()->assertJsonPath('error.code', 'lesson_not_found');
    psOpen($this, $learner, $course->slug, body: ['lesson_id' => $foreignLesson->id])->assertStatus(422)->assertJsonPath('error.code', 'lesson_not_in_course');
    // The existence of a lesson of a course the learner could not open is not revealed.
    psOpen($this, $learner, $course->slug, body: ['lesson_id' => $secretLesson->id])->assertNotFound()->assertJsonPath('error.code', 'lesson_not_found');

    expect($draft->exists)->toBeTrue()->and(PracticeSession::query()->count())->toBe(0);
});

it('lets a learner practice on their own deck', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner] = psWorld();
    $deck = Course::factory()->deckOf($learner->id)->create(['slug' => 'my-deck']);

    psOpen($this, $learner, 'my-deck')->assertCreated()->assertJsonPath('course.slug', 'my-deck');
});

it('lets a learner have ONE active session: 409 with its details, and replace_active finishes it as replaced', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    PracticeKit::fake(PracticeMode::Sentence);
    [$learner, $course, $lesson] = psWorld();

    $first = psOpen($this, $learner, $course->slug, body: ['lesson_id' => $lesson->id])->assertCreated()->json();
    psAnswerCurrent($this, $learner, $first)->assertOk();

    psOpen($this, $learner, $course->slug, 'sentence')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'practice_session_active')
        ->assertJsonPath('error.details', ['session_id' => $first['id'], 'mode' => 'collocation', 'course' => 'english-a1', 'lesson_id' => $lesson->id, 'progress' => ['done' => 1, 'total' => 5]]);
    expect(PracticeSession::query()->count())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($first['id'])->status)->toBe(SessionStatus::Active);

    $second = psOpen($this, $learner, $course->slug, 'sentence', ['replace_active' => true])->assertCreated()->json();

    $old = PracticeSession::query()->findOrFail($first['id']);
    expect($old->status)->toBe(SessionStatus::Finished)
        ->and($old->finish_reason)->toBe(FinishReason::Replaced)
        ->and($old->active_learner_id)->toBeNull()
        ->and($old->finished_at?->toDateTimeString())->toBe(PS_NOW)
        ->and(PracticeSession::query()->findOrFail($second['id'])->active_learner_id)->toBe($learner->id)
        ->and(PracticeSession::query()->where('learner_id', $learner->id)->where('status', 'active')->count())->toBe(1);

    $finished = ProductEvent::query()->where('name', 'practice.session_finished')->sole();
    expect($finished->props)->toMatchArray(['session_id' => $first['id'], 'mode' => 'collocation', 'total' => 5, 'answered' => 1, 'correct_first' => 1, 'reason' => 'replaced'])
        ->and(psEvents('practice.session_started'))->toBe(2);

    // The replaced session still reads (with its summary) but takes no more answers.
    psGet($this, $learner, $first['id'])->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('summary.finish_reason', 'replaced');
    psAnswer($this, $learner, $first['id'], $first['current']['id'], ['skip' => true])->assertStatus(410)->assertJsonPath('error.details.finish_reason', 'replaced');
});

it('lets two learners each have their own active session', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $other = Learner::factory()->create();

    psOpen($this, $learner, $course->slug)->assertCreated();
    psOpen($this, $other, $course->slug)->assertCreated();

    expect(PracticeSession::query()->where('status', 'active')->count())->toBe(2);
});

it('resumes with the very same layout: a reload returns the current element as it was', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();

    $opened = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $firstAgain = psGet($this, $learner, $opened['id'])->assertOk()->json();
    expect($firstAgain['current'])->toBe($opened['current'])
        ->and(Arr::except($firstAgain, ['server_now']))->toBe(Arr::except($opened, ['server_now']));

    $answered = psAnswerCurrent($this, $learner, $opened)->assertOk()->json('session');
    $reload = psGet($this, $learner, $opened['id'])->assertOk()->json();

    expect($reload['current']['id'])->not->toBe($opened['current']['id'])
        ->and($reload['current'])->toBe($answered['current'])
        ->and($reload['progress'])->toBe(['done' => 1, 'total' => 5]);

    $elements = array_column(PracticeSession::query()->findOrFail($opened['id'])->snapshot['elements'], 'id');
    expect($elements[0])->toBe($opened['current']['id'])->and($elements[1])->toBe($reload['current']['id']);
});

it('plays a session to the end: every answer comes with its verdict, the last one finishes it with the summary', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    [$learner, $course] = psWorld();

    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    expect($session['progress']['total'])->toBe(3);

    $last = null;
    for ($i = 0; $i < 3; $i++) {
        $response = psAnswerCurrent($this, $learner, $session)->assertOk();
        $response->assertJsonPath('verdict', 'correct')
            ->assertJsonPath('first_attempt', true)
            ->assertJsonPath('pair', null)
            ->assertJsonPath('reveal.expected', psExpected($session['id'], $session['current']['id']))
            ->assertJsonPath('session.progress.done', $i + 1);
        $last = $response->json();
        $session = $last['session'];
    }

    expect($last['session']['status'])->toBe('finished')
        ->and($last['session']['current'])->toBeNull()
        ->and($last['session']['summary'])->toMatchArray([
            'total' => 3, 'answered' => 3, 'correct_first' => 3, 'typos' => 0, 'hints' => 0, 'mismatches' => null,
            'finish_reason' => 'completed', 'retry_available' => false, 'mistakes' => [],
        ]);

    $row = PracticeSession::query()->findOrFail($session['id']);
    expect($row->status)->toBe(SessionStatus::Finished)
        ->and($row->finish_reason)->toBe(FinishReason::Completed)
        ->and($row->active_learner_id)->toBeNull()
        ->and($row->answered)->toBe(3)
        ->and($row->correct_first)->toBe(3)
        ->and(psEvents('practice.session_finished'))->toBe(1);

    // The summary is readable at any time; the end takes no more answers, and the learner can start the next session.
    psGet($this, $learner, $session['id'])->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('summary.answered', 3);
    psAnswer($this, $learner, $session['id'], $row->snapshot['elements'][0]['id'], ['skip' => true])
        ->assertStatus(410)->assertJsonPath('error.code', 'practice_session_closed')->assertJsonPath('error.details.finish_reason', 'completed');
    psOpen($this, $learner, $course->slug)->assertCreated();
});

it('counts the summary: first tries, typos, hints, and lists what went wrong with the answer given', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 5);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();

    $answers = ['correct', 'wrong', 'typo', 'skip', 'correct'];
    foreach ($answers as $how) {
        $response = psAnswerCurrent($this, $learner, $session, $how)->assertOk();
        $session = $response->json('session');
    }

    $summary = $session['summary'];
    expect($summary)->toMatchArray(['total' => 5, 'answered' => 5, 'correct_first' => 2, 'typos' => 1, 'hints' => 1, 'mismatches' => null, 'retry_available' => true])
        ->and($summary['duration_ms'])->toBe(0)
        ->and(array_column($summary['mistakes'], 'verdict'))->toBe(['wrong', 'typo', 'skipped'])
        ->and(array_column($summary['mistakes'], 'your_answer'))->toBe(['nonsense', 'almost', null])
        ->and($summary['mistakes'][0])->toHaveKeys(['element_id', 'kind', 'label', 'your_answer', 'verdict'])
        ->and($summary['mistakes'][0]['kind'])->toBe('collocation');

    $row = PracticeSession::query()->findOrFail($session['id']);
    expect([$row->answered, $row->correct_first, $row->hints, $row->mismatches])->toBe([5, 2, 1, 0]);

    $attempts = PracticeAttempt::query()->where('session_id', $row->id)->orderBy('id')->get();
    expect($attempts->pluck('kind')->all())->toBe(['answer', 'answer', 'answer', 'skip', 'answer'])
        ->and($attempts->pluck('verdict')->map(fn ($v) => $v?->value)->all())->toBe(['correct', 'wrong', 'typo', 'skipped', 'correct'])
        ->and($attempts->pluck('is_first')->all())->toBe([true, true, true, true, true]);
});

it('measures the duration of a finished session by the server clock', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();

    CarbonImmutable::setTestNow('2026-10-30 10:01:33');
    foreach (range(1, 3) as $ignored) {
        $session = psAnswerCurrent($this, $learner, $session)->assertOk()->json('session');
    }

    expect($session['summary']['duration_ms'])->toBe(93000);
    $finished = ProductEvent::query()->where('name', 'practice.session_finished')->sole();
    expect($finished->props['duration_ms'])->toBe(93000);
});

it('answers 410 to a session past its time, closes it as expired once, and lets the learner start another', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();

    CarbonImmutable::setTestNow('2026-10-31 10:00:00');
    psAnswer($this, $learner, $session['id'], $session['current']['id'], ['skip' => true])
        ->assertStatus(410)->assertJsonPath('error.code', 'practice_session_closed')->assertJsonPath('error.details.finish_reason', 'expired');

    $row = PracticeSession::query()->findOrFail($session['id']);
    expect($row->status)->toBe(SessionStatus::Finished)
        ->and($row->finish_reason)->toBe(FinishReason::Expired)
        ->and($row->active_learner_id)->toBeNull()
        ->and(PracticeAttempt::query()->count())->toBe(0)
        ->and(psEvents('practice.session_finished'))->toBe(1);

    // A second try is 410 again and raises no second event; the summary of the ended session reads (200, not 410).
    psAnswer($this, $learner, $session['id'], $session['current']['id'], ['skip' => true])->assertStatus(410);
    psGet($this, $learner, $session['id'])->assertOk()->assertJsonPath('summary.finish_reason', 'expired');
    expect(psEvents('practice.session_finished'))->toBe(1);

    psOpen($this, $learner, $course->slug)->assertCreated();
});

it('closes an expired session that is only read, and does not block a new one with it', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $expired = psOpen($this, $learner, $course->slug)->assertCreated()->json();

    CarbonImmutable::setTestNow('2026-11-02 10:00:00');
    psGet($this, $learner, $expired['id'])->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('summary.finish_reason', 'expired');
    expect(PracticeSession::query()->findOrFail($expired['id'])->active_learner_id)->toBeNull()
        ->and(psEvents('practice.session_finished'))->toBe(1);

    // Opening with an expired-but-not-yet-closed session in the way: it is closed (expired), not "replaced", and there is no 409.
    CarbonImmutable::setTestNow(PS_NOW);
    $other = Learner::factory()->create();
    $session = PracticeSession::factory()->expired()->create(['learner_id' => $other->id, 'course_id' => $course->id, 'mode' => PracticeMode::Collocation]);
    psOpen($this, $other, $course->slug)->assertCreated();
    expect($session->fresh()?->finish_reason)->toBe(FinishReason::Expired)
        ->and($session->fresh()?->active_learner_id)->toBeNull();
});

it('hands a session of somebody else, or a missing one, the same 404', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, spares: 0);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $stranger = Learner::factory()->create();
    $headers = PracticeKit::headers($stranger);
    $answer = ['request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'answer' => ['skip' => true]];

    foreach ([$session['id'], 999999] as $id) {
        $this->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$id}")->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
        $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$id}/answer", $answer)->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
        $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$id}/replace", ['request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'reason' => 'audio_unavailable'])
            ->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    }
    expect(PracticeAttempt::query()->count())->toBe(0);
    $this->flushHeaders()->getJson("/api/twa/practice/sessions/{$session['id']}")->assertUnauthorized();
});

it('refuses an answer to an element that is not the current one, with the id of the current one', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $elements = array_column(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'], 'id');

    psAnswer($this, $learner, $session['id'], $elements[2], ['text' => 'x'])
        ->assertStatus(409)->assertJsonPath('error.code', 'element_not_current')->assertJsonPath('error.details', ['current' => $elements[0]]);
    psAnswer($this, $learner, $session['id'], 'eNoSuchEl00', ['text' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_element_id');
    expect(PracticeAttempt::query()->count())->toBe(0);
});

it('refuses a second answer to an element, with the verdict it already has', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $first = $session['current']['id'];

    psAnswer($this, $learner, $session['id'], $first, ['text' => 'nonsense'])->assertOk()->assertJsonPath('verdict', 'wrong');
    // Another tab: another request_id, another answer, the same element.
    psAnswer($this, $learner, $session['id'], $first, ['text' => psExpected($session['id'], $first)])
        ->assertStatus(409)->assertJsonPath('error.code', 'answer_already_accepted')->assertJsonPath('error.details', ['verdict' => 'wrong']);

    expect(PracticeAttempt::query()->count())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id']))->correct_first->toBe(0);
});

it('returns the stored result for a repeated request_id: the same bytes, the current view, no second attempt', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $requestId = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    $first = $session['current']['id'];

    $one = psAnswer($this, $learner, $session['id'], $first, ['text' => psExpected($session['id'], $first)], $requestId, 1200)->assertOk();
    $again = psAnswer($this, $learner, $session['id'], $first, ['text' => psExpected($session['id'], $first)], $requestId, 1200)->assertOk();

    $part = fn (TestResponse $r): string => json_encode(Arr::only($r->json(), ['verdict', 'first_attempt', 'reveal', 'pair']));
    expect($part($again))->toBe($part($one))
        ->and($again->json('session.progress'))->toBe(['done' => 1, 'total' => 3])
        ->and(PracticeAttempt::query()->count())->toBe(1)
        ->and(PracticeSession::query()->findOrFail($session['id'])->correct_first)->toBe(1);

    // Same key, other element and other answer: the answer of the FIRST request, nothing new is written.
    $other = psAnswer($this, $learner, $session['id'], 'eSomethingElse', ['skip' => true], $requestId)->assertOk();
    expect($part($other))->toBe($part($one))->and(PracticeAttempt::query()->count())->toBe(1);

    // The view is the CURRENT one: after the next answer the repeat shows the progress of now.
    psAnswerCurrent($this, $learner, $one->json('session'))->assertOk();
    psAnswer($this, $learner, $session['id'], $first, ['text' => 'x'], $requestId)->assertOk()->assertJsonPath('session.progress.done', 2);

    $attempt = PracticeAttempt::query()->orderBy('id')->firstOrFail();
    expect($attempt->time_spent_ms)->toBe(1200)
        ->and($attempt->accepted_at->toDateTimeString())->toBe(PS_NOW)
        ->and($attempt->request_id)->toBe($requestId);
});

it('answers the repeat of the LAST answer with the stored result, not with 410, even though the session has ended', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    foreach (range(1, 2) as $ignored) {
        $session = psAnswerCurrent($this, $learner, $session)->assertOk()->json('session');
    }
    $requestId = (string) Str::uuid();
    $elementId = $session['current']['id'];

    $last = psAnswer($this, $learner, $session['id'], $elementId, ['text' => psExpected($session['id'], $elementId)], $requestId)->assertOk();
    expect($last->json('session.status'))->toBe('finished');

    // The network dropped the response; the client sends it again.
    $again = psAnswer($this, $learner, $session['id'], $elementId, ['text' => psExpected($session['id'], $elementId)], $requestId)->assertOk();
    expect($again->json('verdict'))->toBe('correct')
        ->and($again->json('session.status'))->toBe('finished')
        ->and($again->json('session.summary.answered'))->toBe(3)
        ->and(PracticeAttempt::query()->count())->toBe(3)
        ->and(psEvents('practice.session_finished'))->toBe(1);
});

it('validates the form of an answer: 422 for the envelope, 422 invalid_answer_shape for the shape of the mode', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $uri = "/api/twa/practice/sessions/{$session['id']}/answer";
    $headers = PracticeKit::headers($learner);
    $element = $session['current']['id'];
    $ok = ['request_id' => (string) Str::uuid(), 'element_id' => $element, 'answer' => ['text' => 'x']];

    $this->withHeaders($headers)->postJson($uri, [...$ok, 'request_id' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('request_id');
    $this->withHeaders($headers)->postJson($uri, Arr::except($ok, ['request_id']))->assertStatus(422)->assertJsonValidationErrors('request_id');
    $this->withHeaders($headers)->postJson($uri, Arr::except($ok, ['element_id']))->assertStatus(422)->assertJsonValidationErrors('element_id');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'element_id' => str_repeat('e', 17)])->assertStatus(422)->assertJsonValidationErrors('element_id');
    $this->withHeaders($headers)->postJson($uri, Arr::except($ok, ['answer']))->assertStatus(422)->assertJsonValidationErrors('answer');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => 'text'])->assertStatus(422)->assertJsonValidationErrors('answer');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['text' => str_repeat('a', 201)]])->assertStatus(422)->assertJsonValidationErrors('answer.text');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['order' => array_fill(0, 9, 'fAAAAAAAAAA')]])->assertStatus(422)->assertJsonValidationErrors('answer.order');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['order' => [str_repeat('f', 17)]]])->assertStatus(422)->assertJsonValidationErrors('answer.order.0');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['pairs' => array_fill_keys(range(1, 5), 'rAAAAAAAAAA')]])->assertStatus(422)->assertJsonValidationErrors('answer.pairs');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['text' => 'x', 'junk' => str_repeat('j', 5000)]])->assertStatus(422)->assertJsonValidationErrors('answer');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'time_spent_ms' => -1])->assertStatus(422)->assertJsonValidationErrors('time_spent_ms');

    // The shape is the mode's: here "text or skip".
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['option_id' => 'oAAAAAAAAAA']])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer_shape')->assertJsonPath('error.details', ['expected' => 'text_or_skip']);
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => ['text' => ['x']]])->assertStatus(422)->assertJsonValidationErrors('answer.text');
    $this->withHeaders($headers)->postJson($uri, [...$ok, 'answer' => []])->assertStatus(422)->assertJsonValidationErrors('answer');

    expect(PracticeAttempt::query()->count())->toBe(0);
});

it('strips NUL bytes out of the answer before it is stored (jsonb refuses them)', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();

    psAnswer($this, $learner, $session['id'], $session['current']['id'], ['text' => "no\0nsense"])->assertOk();

    expect(PracticeAttempt::query()->sole()->payload)->toBe(['text' => 'nonsense']);
});

it('plays a round of pairs: the mistakes are counted at once, a round ends when every word is matched, never earlier', function (): void {
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'pairs')->assertCreated()->json();
    $row = PracticeSession::query()->findOrFail($session['id']);
    $rounds = $row->snapshot['elements'];
    $round = $rounds[0];
    [$a, $b, $c] = $round['left'];
    $right = array_keys($round['pairs']);
    $pairs = $round['pairs'];

    expect($session['current'])->toHaveKeys(['id', 'kind', 'left', 'right', 'matched', 'mismatches'])
        ->and($session['current'])->not->toHaveKey('pairs')
        ->and($session['progress']['total'])->toBe(3);

    // The right card of ANOTHER pair: a mistake, the round goes on, the right pair is not given away.
    $wrongRight = $pairs[$b['id']];
    $miss = psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $a['id'], 'right_id' => $wrongRight])->assertOk();
    $miss->assertJsonPath('verdict', 'wrong')->assertJsonPath('first_attempt', true)->assertJsonPath('reveal', null)
        ->assertJsonPath('pair', ['left_id' => $a['id'], 'right_id' => $wrongRight])->assertJsonPath('round_done', false)
        ->assertJsonPath('session.counters.mismatches', 1)->assertJsonPath('session.progress.done', 0)
        ->assertJsonPath('session.current.mismatches', 1)->assertJsonPath('session.current.matched', []);

    // The first mistake of a word stays its first mistake: `a` right after, by the second try, is not a first-try hit.
    $hit = psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $a['id'], 'right_id' => $pairs[$a['id']]])->assertOk();
    $hit->assertJsonPath('verdict', 'correct')->assertJsonPath('first_attempt', false)->assertJsonPath('round_done', false)->assertJsonPath('session.counters.correct_first', 0);

    psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $a['id'], 'right_id' => $pairs[$a['id']]])
        ->assertStatus(409)->assertJsonPath('error.code', 'pair_already_matched')->assertJsonPath('error.details', ['left_id' => $a['id']]);
    psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => 'lNoSuchLeft', 'right_id' => $pairs[$b['id']]])->assertStatus(422)->assertJsonPath('error.code', 'unknown_element_id');
    psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $b['id']])->assertStatus(422)->assertJsonPath('error.code', 'invalid_answer_shape')->assertJsonPath('error.details.expected', 'left_right');

    // The second round is not the current one while the first is not done.
    psAnswer($this, $learner, $session['id'], $rounds[1]['id'], ['left_id' => $rounds[1]['left'][0]['id'], 'right_id' => array_values($rounds[1]['pairs'])[0]])
        ->assertStatus(409)->assertJsonPath('error.code', 'element_not_current')->assertJsonPath('error.details.current', $round['id']);

    psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $b['id'], 'right_id' => $pairs[$b['id']]])->assertOk()
        ->assertJsonPath('first_attempt', true)->assertJsonPath('round_done', false)->assertJsonPath('session.counters.correct_first', 1);
    $done = psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $c['id'], 'right_id' => $pairs[$c['id']]])->assertOk();
    $done->assertJsonPath('round_done', true)->assertJsonPath('session.progress', ['done' => 1, 'total' => 3])
        ->assertJsonPath('session.counters', ['correct_first' => 2, 'hints' => 0, 'mismatches' => 1])
        ->assertJsonPath('session.current.id', $rounds[1]['id']);

    $attempts = PracticeAttempt::query()->where('session_id', $session['id'])->orderBy('id')->get();
    expect($attempts->pluck('kind')->unique()->all())->toBe(['pair'])
        ->and($attempts->pluck('is_first')->all())->toBe([true, false, true, true])
        ->and($right)->toHaveCount(3);

    // The remaining rounds, then the summary: the words of `wrong_left` are the mistakes ("label — translation").
    foreach ([1, 2] as $r) {
        foreach ($rounds[$r]['left'] as $left) {
            $last = psAnswer($this, $learner, $session['id'], $rounds[$r]['id'], ['left_id' => $left['id'], 'right_id' => $rounds[$r]['pairs'][$left['id']]])->assertOk();
        }
    }
    $summary = $last->json('session.summary');
    expect($summary)->toMatchArray(['total' => 3, 'answered' => 3, 'correct_first' => 8, 'mismatches' => 1, 'typos' => 0, 'hints' => 0, 'retry_available' => false])
        ->and($summary['mistakes'])->toHaveCount(1)
        ->and($summary['mistakes'][0]['label'])->toBe($a['text'].' — слово '.substr($a['text'], 5));
});

it('repeats the mistakes: a new session from the wrong answers of a finished one, the old one stays as it was', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 5);
    PracticeKit::fake(PracticeMode::Sentence, candidates: 5);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $itemOf = fn (string $elementId): int => collect(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'])->firstWhere('id', $elementId)['item_id'];

    $mistaken = [];
    foreach (['correct', 'wrong', 'typo', 'skip', 'correct'] as $how) {
        $elementId = $session['current']['id'];
        if ($how !== 'correct') {
            $mistaken[] = $itemOf($elementId);
        }
        $session = psAnswerCurrent($this, $learner, $session, $how)->assertOk()->json('session');
    }
    $before = PracticeSession::query()->findOrFail($session['id'])->only(['status', 'answered', 'correct_first', 'finish_reason', 'state']);
    expect($session['summary']['retry_available'])->toBeTrue();

    // The body names another mode and another course - the session of the retry decides.
    $retry = psOpen($this, $learner, 'no-such-course', 'sentence', ['retry_of' => $session['id'], 'source' => 'hub'])->assertCreated()->json();

    expect($retry['mode'])->toBe('collocation')
        ->and($retry['source'])->toBe('retry')
        ->and($retry['retry_of'])->toBe($session['id'])
        ->and($retry['course']['slug'])->toBe('english-a1')
        ->and($retry['progress']['total'])->toBe(3);
    $items = array_column(PracticeSession::query()->findOrFail($retry['id'])->snapshot['elements'], 'item_id');
    sort($items);
    sort($mistaken);
    expect($items)->toBe($mistaken)
        ->and(PracticeSession::query()->findOrFail($session['id'])->only(['status', 'answered', 'correct_first', 'finish_reason', 'state']))->toBe($before)
        ->and(PracticeSession::query()->findOrFail($retry['id'])->retry_of_session_id)->toBe($session['id']);
    $started = ProductEvent::query()->where('name', 'practice.session_started')->orderByDesc('id')->firstOrFail();
    expect($started->props['retry'])->toBeTrue()->and($started->props['source'])->toBe('retry');
});

it('refuses to repeat what cannot be repeated: nothing wrong, not finished, not yours, not there, one session already on', function (): void {
    PracticeKit::fake(PracticeMode::Collocation, candidates: 3);
    [$learner, $course] = psWorld();

    $clean = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $active = $clean;
    // Not finished yet: 409.
    psOpen($this, $learner, $course->slug, body: ['retry_of' => $active['id']])->assertStatus(409)->assertJsonPath('error.code', 'retry_not_available');

    foreach (range(1, 3) as $ignored) {
        $clean = psAnswerCurrent($this, $learner, $clean)->assertOk()->json('session');
    }
    expect($clean['summary']['retry_available'])->toBeFalse();
    psOpen($this, $learner, $course->slug, body: ['retry_of' => $clean['id']])->assertStatus(409)->assertJsonPath('error.code', 'retry_not_available');

    // Somebody else's session and a missing one are "not found".
    $stranger = Learner::factory()->create();
    psOpen($this, $stranger, $course->slug, body: ['retry_of' => $clean['id']])->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    psOpen($this, $learner, $course->slug, body: ['retry_of' => 999999])->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');

    // A session with a mistake can be repeated - but one session at a time.
    $wrong = psOpen($this, $learner, $course->slug)->assertCreated()->json();
    $wrong = psAnswerCurrent($this, $learner, $wrong, 'wrong')->assertOk()->json('session');
    foreach (range(1, 2) as $ignored) {
        $wrong = psAnswerCurrent($this, $learner, $wrong)->assertOk()->json('session');
    }
    $retry = psOpen($this, $learner, $course->slug, body: ['retry_of' => $wrong['id']])->assertCreated();
    psOpen($this, $learner, $course->slug, body: ['retry_of' => $wrong['id']])->assertStatus(409)->assertJsonPath('error.code', 'practice_session_active');

    // The mode of the retried session may be switched off by now: the retry is a new session of that mode.
    $retry->assertJsonPath('progress.total', 1);
    config(['practice.modes.collocation' => false]);
    psOpen($this, $learner, $course->slug, body: ['retry_of' => $wrong['id'], 'replace_active' => true])->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled');
});

it('repeats only the words of a pairs session that went wrong, and only when there are enough of them', function (): void {
    PracticeKit::fake(PracticeMode::Pairs, candidates: 9);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'pairs')->assertCreated()->json();
    $rounds = PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'];

    // A mistake in every word of the first round (3 words), the others are clean.
    foreach ($rounds as $index => $round) {
        foreach ($round['left'] as $left) {
            $rightIds = array_values($round['pairs']);
            if ($index === 0) {
                $wrongRight = collect($rightIds)->first(fn (string $id): bool => $id !== $round['pairs'][$left['id']]);
                psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $left['id'], 'right_id' => $wrongRight])->assertOk()->assertJsonPath('verdict', 'wrong');
            }
            $response = psAnswer($this, $learner, $session['id'], $round['id'], ['left_id' => $left['id'], 'right_id' => $round['pairs'][$left['id']]])->assertOk();
        }
    }
    expect($response->json('session.summary.retry_available'))->toBeTrue()
        ->and($response->json('session.summary.mistakes'))->toHaveCount(3);

    $retry = psOpen($this, $learner, $course->slug, 'pairs', ['retry_of' => $session['id']])->assertCreated()->json();
    $words = collect(PracticeSession::query()->findOrFail($retry['id'])->snapshot['elements'])->pluck('left')->flatten(1)->pluck('word_id')->sort()->values()->all();
    $expected = collect($rounds[0]['left'])->pluck('word_id')->sort()->values()->all();
    expect($retry['progress']['total'])->toBe(1)
        ->and($words)->toBe($expected);
});

it('swaps a dictation whose audio does not play for the next spare, twice at most, without a penalty', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 8, spares: 3);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'dictation')->assertCreated()->json();
    $row = PracticeSession::query()->findOrFail($session['id']);
    $first = $session['current'];

    expect($first['audio_url'])->toBe("/api/twa/practice/sessions/{$session['id']}/audio/{$first['id']}")
        ->and($first['replacements_left'])->toBe(2)
        ->and($row->snapshot['pool'])->toHaveCount(3);

    $uri = "/api/twa/practice/sessions/{$session['id']}/replace";
    $headers = PracticeKit::headers($learner);
    $body = fn (string $elementId): array => ['request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'reason' => 'audio_unavailable'];

    $swapped = $this->withHeaders($headers)->postJson($uri, $body($first['id']))->assertOk();
    $swapped->assertJsonPath('progress', ['done' => 0, 'total' => 5])->assertJsonPath('current.replacements_left', 1);
    expect($swapped->json('current.id'))->not->toBe($first['id'])
        ->and(PracticeSession::query()->findOrFail($session['id'])->snapshot['pool'])->toHaveCount(2);

    $again = $this->withHeaders($headers)->postJson($uri, $body($swapped->json('current.id')))->assertOk();
    $again->assertJsonPath('current.replacements_left', 0);

    // The limit: 409 with the reason; the old element is gone from the snapshot for good.
    $this->withHeaders($headers)->postJson($uri, $body($again->json('current.id')))
        ->assertStatus(409)->assertJsonPath('error.code', 'replace_not_allowed')->assertJsonPath('error.details', ['reason' => 'limit']);

    $final = PracticeSession::query()->findOrFail($session['id']);
    expect(array_column($final->snapshot['elements'], 'id'))->not->toContain($first['id'])
        ->and($final->state['replacements'])->toBe(2)
        ->and($final->state['replaced'])->toHaveCount(2)
        ->and($final->total)->toBe(5)
        ->and($final->answered)->toBe(0)
        ->and(PracticeAttempt::query()->where('kind', 'replace')->count())->toBe(2)
        ->and(psEvents('practice.audio_failed'))->toBe(2);

    $event = ProductEvent::query()->where('name', 'practice.audio_failed')->orderBy('id')->firstOrFail();
    expect($event->props)->toMatchArray(['session_id' => $session['id'], 'source' => 'phrase', 'reason' => 'client_report', 'verified' => false]);
});

it('does not swap twice for one request_id, and swaps only the current element of a dictation', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 6, spares: 2);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'dictation')->assertCreated()->json();
    $uri = "/api/twa/practice/sessions/{$session['id']}/replace";
    $headers = PracticeKit::headers($learner);
    $elements = array_column(PracticeSession::query()->findOrFail($session['id'])->snapshot['elements'], 'id');
    $requestId = (string) Str::uuid();

    $one = $this->withHeaders($headers)->postJson($uri, ['request_id' => $requestId, 'element_id' => $elements[0], 'reason' => 'audio_unavailable'])->assertOk();
    $repeat = $this->withHeaders($headers)->postJson($uri, ['request_id' => $requestId, 'element_id' => $elements[0], 'reason' => 'audio_unavailable'])->assertOk();
    expect($repeat->json('current.id'))->toBe($one->json('current.id'))
        ->and(PracticeSession::query()->findOrFail($session['id'])->state['replacements'])->toBe(1)
        ->and(PracticeAttempt::query()->count())->toBe(1);

    // Not the current element: 409 not_current; an id nobody knows: 422; a reason nobody knows: 422.
    $this->withHeaders($headers)->postJson($uri, ['request_id' => (string) Str::uuid(), 'element_id' => $elements[3], 'reason' => 'audio_unavailable'])
        ->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'not_current']);
    $this->withHeaders($headers)->postJson($uri, ['request_id' => (string) Str::uuid(), 'element_id' => 'eNoSuchEl00', 'reason' => 'audio_unavailable'])
        ->assertStatus(422)->assertJsonPath('error.code', 'unknown_element_id');
    $this->withHeaders($headers)->postJson($uri, ['request_id' => (string) Str::uuid(), 'element_id' => $elements[3], 'reason' => 'boring'])
        ->assertStatus(422)->assertJsonValidationErrors('reason');

    // Only a dictation can be swapped.
    PracticeSession::query()->delete();
    $plain = psOpen($this, $learner, $course->slug, 'collocation')->assertCreated()->json();
    $this->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$plain['id']}/replace", ['request_id' => (string) Str::uuid(), 'element_id' => $plain['current']['id'], 'reason' => 'audio_unavailable'])
        ->assertStatus(409)->assertJsonPath('error.code', 'replace_not_allowed')->assertJsonPath('error.details', ['reason' => 'not_dictation']);
});

it('drops the element when the pool has no spare: the session gets shorter, nothing is counted against the learner, one element stays at least', function (): void {
    config(['practice.solo.dictation.max_replacements' => 10]);
    PracticeKit::fake(PracticeMode::Dictation, candidates: 3, spares: 0);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'dictation')->assertCreated()->json();
    $headers = PracticeKit::headers($learner);
    $uri = "/api/twa/practice/sessions/{$session['id']}/replace";
    $replace = fn (string $elementId) => $this->withHeaders($headers)->postJson($uri, ['request_id' => (string) Str::uuid(), 'element_id' => $elementId, 'reason' => 'audio_unavailable']);

    $first = $session['current']['id'];
    $next = $replace($first)->assertOk();
    $next->assertJsonPath('progress', ['done' => 0, 'total' => 2]);
    expect($next->json('current.id'))->not->toBe($first);

    $last = $replace($next->json('current.id'))->assertOk();
    $last->assertJsonPath('progress.total', 1);

    // One element is the least a session has.
    $replace($last->json('current.id'))->assertStatus(409)->assertJsonPath('error.details', ['reason' => 'limit']);
    $row = PracticeSession::query()->findOrFail($session['id']);
    expect($row->total)->toBe(1)
        ->and($row->snapshot['elements'])->toHaveCount(1)
        ->and($row->status)->toBe(SessionStatus::Active)
        ->and($row->state['replaced'])->toHaveCount(2);

    // The last element can still be answered, and then the session is over.
    $done = psAnswer($this, $learner, $session['id'], $last->json('current.id'), ['skip' => true])->assertOk();
    $done->assertJsonPath('session.status', 'finished')->assertJsonPath('session.summary.total', 1);
});

it('finishes the session when the element that went was the last one still to be answered', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 3, spares: 0);
    [$learner, $course] = psWorld();
    $session = psOpen($this, $learner, $course->slug, 'dictation')->assertCreated()->json();
    foreach (range(1, 2) as $ignored) {
        $session = psAnswerCurrent($this, $learner, $session)->assertOk()->json('session');
    }

    $response = $this->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/replace", [
        'request_id' => (string) Str::uuid(),
        'element_id' => $session['current']['id'],
        'reason' => 'audio_unavailable',
    ])->assertOk();

    $response->assertJsonPath('status', 'finished')->assertJsonPath('current', null)->assertJsonPath('summary.total', 2)->assertJsonPath('summary.answered', 2);
    $row = PracticeSession::query()->findOrFail($session['id']);
    expect($row->finish_reason)->toBe(FinishReason::Completed)
        ->and($row->active_learner_id)->toBeNull()
        ->and(psEvents('practice.session_finished'))->toBe(1)
        ->and(psEvents('practice.audio_failed'))->toBe(1);
});

it('gives the session of a parallel tab to the loser of the race, and refuses it when the practice is another', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    PracticeKit::fake(PracticeMode::Sentence);
    [$learner, $course] = psWorld();

    // The other tab has COMMITTED its session between our look ("nobody is active") and our INSERT. On one connection that is
    // played with a scope that hides the sessions from the first look only: the unique index still sees the row.
    $raceWith = function (PracticeMode $mode) use ($learner, $course): void {
        DB::table('practice_sessions')->insert([
            'learner_id' => $learner->id, 'active_learner_id' => $learner->id, 'mode' => $mode->value, 'course_id' => $course->id,
            'source' => 'hub', 'status' => 'active', 'snapshot' => json_encode(['mode' => $mode->value, 'course_slug' => 'english-a1']),
            'state' => json_encode(['current' => 0]), 'total' => 3, 'locale' => 'ru',
            'expires_at' => now()->addDay(), 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $hidden = true;
        PracticeSession::flushEventListeners();
        PracticeSession::addGlobalScope('race', function (Builder $query) use (&$hidden): void {
            if ($hidden) {
                $query->whereRaw('1 = 0');
            }
        });
        PracticeSession::creating(function () use (&$hidden): void {
            $hidden = false; // our INSERT is next: from here on the row of the other tab is seen
        });
    };

    $raceWith(PracticeMode::Collocation);
    $same = psOpen($this, $learner, $course->slug, 'collocation')->assertCreated();
    expect(PracticeSession::query()->count())->toBe(1)
        ->and($same->json('id'))->toBe((int) PracticeSession::query()->value('id'))
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(1);

    // The winner is another practice and the loser did not ask to replace: the 409 of the winner.
    PracticeSession::query()->delete();
    $raceWith(PracticeMode::Sentence);
    psOpen($this, $learner, $course->slug, 'collocation')->assertStatus(409)->assertJsonPath('error.code', 'practice_session_active')->assertJsonPath('error.details.mode', 'sentence');
    expect(PracticeSession::query()->count())->toBe(1);

    // ... and with replace_active the loser replaces the winner at the second try.
    PracticeSession::query()->delete();
    $raceWith(PracticeMode::Sentence);
    $replaced = psOpen($this, $learner, $course->slug, 'collocation', ['replace_active' => true])->assertCreated();
    expect($replaced->json('mode'))->toBe('collocation')
        ->and(PracticeSession::query()->where('status', 'active')->count())->toBe(1)
        ->and(PracticeSession::query()->where('mode', 'sentence')->sole()->finish_reason)->toBe(FinishReason::Replaced);
});

it('hands the hub only the active session of the learner', function (): void {
    PracticeKit::fake(PracticeMode::Collocation);
    [$learner, $course, $lesson] = psWorld();
    $other = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);

    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}&lesson_id={$lesson->id}")->assertOk()->assertJsonPath('active_session', null);

    $session = psOpen($this, $learner, $course->slug, body: ['lesson_id' => $lesson->id])->assertCreated()->json();
    psOpen($this, $other, $course->slug)->assertCreated();
    psAnswerCurrent($this, $learner, $session)->assertOk();

    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk()
        ->assertJsonPath('active_session', ['id' => $session['id'], 'mode' => 'collocation', 'course' => 'english-a1', 'lesson_id' => $lesson->id, 'progress' => ['done' => 1, 'total' => 5]]);

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    $this->withHeaders($headers)->getJson("/api/twa/practice/hub?course={$course->slug}")->assertOk()->assertJsonPath('active_session', null);
    expect(PracticeSession::query()->findOrFail($session['id'])->finish_reason)->toBe(FinishReason::Expired);
});
