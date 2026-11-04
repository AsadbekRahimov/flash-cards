<?php

declare(strict_types=1);

use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\RunEvent;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Practice\Enums\Verdict;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use App\Models\GameWallet;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 §12.8, docs/04 A.18: the HTTP flows of a run - start, read, answer, hint, abandon, the audio of an element - and the errors
 * of each, with the codes and the statuses the document names. The life of a run itself is GameRunServiceTest's.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function apiStart(Learner $learner, string $course, int $lessonId, array $extra = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course, 'lesson_id' => $lessonId, ...$extra]);
}

function apiRun(Learner $learner, int $id): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/game/runs/{$id}");
}

/** @param array<string, mixed> $answer */
function apiAnswer(Learner $learner, int $id, string $elementId, array $answer, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$id}/answer", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
        'answer' => $answer,
    ]);
}

function apiHint(Learner $learner, int $id, string $elementId, ?string $requestId = null): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$id}/hint", [
        'request_id' => $requestId ?? (string) Str::uuid(),
        'element_id' => $elementId,
    ]);
}

function apiAbandon(Learner $learner, int $id, array $body = ['confirm' => true]): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/game/runs/{$id}/abandon", $body);
}

function apiAudio(Learner $learner, int $id, string $element): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->get("/api/twa/game/runs/{$id}/audio/{$element}");
}

/** The learner starts a new minute for the limiters of the routes (the clock of a test is frozen). */
function apiNewMinute(Learner $learner): void
{
    foreach (['twa-api', 'twa-game-start', 'twa-game-answer'] as $name) {
        RateLimiter::clear(md5($name.$name.'|'.$learner->id));
    }
}

/** @return array{code: string, details: array<string, mixed>} */
function apiError(TestResponse $response, int $status, string $code): array
{
    $response->assertStatus($status)->assertJsonPath('error.code', $code);

    return ['code' => $code, 'details' => (array) $response->json('error.details', [])];
}

/** Puts the bazaar on the element at `$at` of the run (the events are fixed in the snapshot at the start). */
function apiBazaarAt(GameRun $run, int $at): void
{
    $run->forceFill(['snapshot' => [...$run->snapshot, 'events' => [['at' => $at, 'type' => RunEvent::Bazaar->value]]]])->save();
}

it('plays a run over HTTP from the start to the end: every kind that comes, the counters, the summary, the closed run', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();

    $started = apiStart($learner, $course->slug, $lesson->id)->assertCreated();
    $id = $started->json('id');
    $started->assertJsonPath('status', 'active')->assertJsonPath('kind', 'stop')->assertJsonPath('position', 1)->assertJsonPath('summary', null)
        ->assertJsonPath('boosters', ['available' => [], 'used' => []])->assertJsonPath('server_now', '2026-11-05T10:00:00.000Z');
    expect($started->json('total'))->toBeGreaterThanOrEqual(6)->and($started->json('current.element.id'))->toBeString();

    $kinds = [];
    $last = null;
    for ($guard = 0; $guard < 100; $guard++) {
        $run = GameRun::query()->findOrFail($id);
        if (! $run->isActive()) {
            break;
        }
        $element = GameKit::current($run);
        $kinds[$element['kind']] = true;
        expect(apiRun($learner, $id)->assertOk()->json('current.element.id'))->toBe($element['id']);
        foreach (GameKit::rightAnswers($element) as $answer) {
            $last = apiAnswer($learner, $id, $element['id'], $answer)->assertOk();
            expect($last->json('verdict'))->toBe('correct')->and($last->json('run.id'))->toBe($id);
        }
        // the clock of a test is frozen: the 60 requests a minute of `twa-api` are not what is tested here
        apiNewMinute($learner);
    }

    $done = apiRun($learner, $id)->assertOk();
    expect($last?->json('run.status'))->toBe('finished')
        ->and($done->json('status'))->toBe('finished')->and($done->json('finish_reason'))->toBe('completed')
        ->and($done->json('current'))->toBeNull()
        ->and($done->json('summary.stars'))->toBe(3)->and($done->json('summary.mistakes'))->toBe([])
        ->and($done->json('position'))->toBe($done->json('total'))
        ->and($last?->json('run.summary'))->toBe($done->json('summary'))
        ->and(array_diff(array_keys($kinds), ['translate_fwd', 'translate_back', 'listen', 'spell', 'gap', 'pairs_round', 'collocation', 'sentence', 'dictation']))->toBe([]);

    // A finished run takes no more answers: 410 with the reason.
    $element = $run->snapshot['elements'][0];
    expect(apiError(apiAnswer($learner, $id, $element['id'], ['skip' => true]), 410, 'game_run_closed')['details'])->toBe(['finish_reason' => 'completed']);
    apiError(apiHint($learner, $id, $element['id']), 410, 'game_run_closed');

    // The learner's day: one product event of the start, the finish.
    expect(ProductEvent::query()->where('name', 'game.run_started')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(1);
});

it('lists the mistakes of a failed run in its summary, with the label and the answer the learner gave', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');

    $summary = null;
    for ($guard = 0; $guard < 30; $guard++) {
        $run = GameRun::query()->findOrFail($id);
        if (! $run->isActive()) {
            break;
        }
        $element = GameKit::current($run);
        $summary = apiAnswer($learner, $id, $element['id'], GameKit::wrongAnswer($element))->assertOk();
        apiNewMinute($learner);
    }

    $body = apiRun($learner, $id)->assertOk();
    expect($body->json('finish_reason'))->toBe('failed')->and($body->json('summary.stars'))->toBe(0)
        ->and($body->json('summary.mistakes'))->toHaveCount(3)
        ->and($body->json('summary.mistakes.0'))->toHaveKeys(['element_id', 'kind', 'label', 'your_answer', 'verdict'])
        ->and($summary?->json('flask_lost'))->toBeTrue();
});

it('answers 404 game_run_not_found to a stranger\'s run and to a run that does not exist, on every route', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));
    $stranger = Learner::factory()->create();

    foreach ([$id, $id + 1000] as $target) {
        apiError(apiRun($stranger, $target), 404, 'game_run_not_found');
        apiError(apiAnswer($stranger, $target, $element['id'], ['skip' => true]), 404, 'game_run_not_found');
        apiError(apiHint($stranger, $target, $element['id']), 404, 'game_run_not_found');
        apiError(apiAbandon($stranger, $target), 404, 'game_run_not_found');
        apiError(apiAudio($stranger, $target, $element['id']), 404, 'game_run_not_found');
    }

    // The run of the learner is untouched by the strangers' attempts.
    expect(GameRun::query()->findOrFail($id)->isActive())->toBeTrue()->and(GameRunAttempt::query()->count())->toBe(0);
});

it('refuses with the 409 codes of the document: element_not_current, answer_already_accepted, request_id_reused, game_run_active', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $run = GameRun::query()->findOrFail($id);
    $elements = $run->snapshot['elements'];

    expect(apiError(apiAnswer($learner, $id, $elements[2]['id'], ['skip' => true]), 409, 'element_not_current')['details'])->toBe(['current' => $elements[0]['id']]);
    apiError(apiAnswer($learner, $id, 'eNope', ['skip' => true]), 422, 'unknown_element_id');

    // The same request_id is the same answer; as a hint it is another thing.
    $requestId = (string) Str::uuid();
    $first = apiAnswer($learner, $id, $elements[0]['id'], GameKit::rightAnswers($elements[0])[0], $requestId)->assertOk();
    $again = apiAnswer($learner, $id, $elements[0]['id'], ['skip' => true], strtoupper($requestId))->assertOk();
    expect(array_diff_key($again->json(), ['run' => 1]))->toBe(array_diff_key($first->json(), ['run' => 1]))
        ->and(GameRunAttempt::query()->where('run_id', $id)->count())->toBe(1);
    apiError(apiHint($learner, $id, $elements[1]['id'], $requestId), 409, 'request_id_reused');

    // An element that is current and answered already (a state a double write could leave): 409 with the verdict it got.
    $state = RunState::fromArray(GameRun::query()->findOrFail($id)->state);
    $state->markAnswered($elements[1]['id'], Verdict::Correct, true, 10, false, null, '2026-11-05T10:00:00.000Z');
    GameRun::query()->whereKey($id)->update(['state' => $state->toArray()]);
    expect(apiError(apiAnswer($learner, $id, $elements[1]['id'], ['skip' => true]), 409, 'answer_already_accepted')['details'])->toBe(['verdict' => 'correct']);

    // A second start on another stop while this one is on.
    $other = Lesson::factory()->for($lesson->stage)->create(['number' => 2]);
    foreach (range(1, 6) as $i) {
        GameKit::word($other, $learner, "otherword{$i}", "другое {$i}");
    }
    $busy = apiError(apiStart($learner, $course->slug, $other->id), 409, 'game_run_active')['details'];
    expect($busy['run_id'])->toBe($id)->and($busy['kind'])->toBe('stop')->and($busy['progress']['total'])->toBe($run->total);

    // The same stop again is the run that is on; with the flag it is a new run and the old one is abandoned.
    expect(apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id'))->toBe($id);
    $new = apiStart($learner, $course->slug, $lesson->id, ['replace_active' => true])->assertCreated()->json('id');
    expect($new)->not->toBe($id)->and(GameRun::query()->findOrFail($id)->finish_reason)->toBe(RunFinishReason::Abandoned);
});

it('refuses a bad start: an unknown or an unavailable course and lesson, a stop not started, a kind that is not here yet', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);

    apiError(apiStart($learner, 'no-such-course', $lesson->id), 404, 'course_not_found');
    apiError(apiStart($learner, $course->slug, $lesson->id + 999), 404, 'lesson_not_found');
    [$other, $otherLesson] = [Learner::factory()->create(), $lesson];
    apiError(apiStart($other, $course->slug, $otherLesson->id), 409, 'stop_locked');

    // The body is validated before anything happens: an unknown kind, the boss of a class without its caravan (G2.9a: see ClassCaravanTest).
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'nonsense', 'course' => $course->slug, 'lesson_id' => $lesson->id])
        ->assertStatus(422)->assertJsonValidationErrors('kind');
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'class_boss', 'course' => $course->slug, 'lesson_id' => $lesson->id])
        ->assertStatus(422)->assertJsonValidationErrors('class_caravan_id');
    test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop'])->assertStatus(422)->assertJsonValidationErrors(['course', 'lesson_id']);
    expect(GameRun::query()->count())->toBe(0);
});

it('refuses a stop with too few words with 422 game_not_enough_material and its details', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(2);

    $details = apiError(apiStart($learner, $course->slug, $lesson->id), 422, 'game_not_enough_material')['details'];

    expect($details)->toHaveKeys(['available', 'required', 'reason']);
});

it('validates the body of an answer, a hint and an abandon', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));
    $headers = PracticeKit::headers($learner);

    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => 'not-a-uuid', 'element_id' => $element['id'], 'answer' => ['skip' => true]])
        ->assertStatus(422)->assertJsonValidationErrors('request_id');
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id']])
        ->assertStatus(422)->assertJsonValidationErrors('answer');
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => ['text' => str_repeat('x', 201)]])
        ->assertStatus(422)->assertJsonValidationErrors('answer.text');
    test()->withHeaders($headers)->postJson("/api/twa/game/runs/{$id}/hint", ['element_id' => $element['id']])
        ->assertStatus(422)->assertJsonValidationErrors('request_id');
    apiAbandon($learner, $id, [])->assertStatus(422)->assertJsonValidationErrors('confirm');
    apiAbandon($learner, $id, ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors('confirm');

    // The shape of the answer is the kind's to check.
    $wrongShape = in_array($element['kind'], ['spell', 'gap'], true) ? ['option_id' => 'x'] : ['text' => 'x'];
    apiError(apiAnswer($learner, $id, $element['id'], $wrongShape), 422, 'invalid_answer_shape');
    if (in_array($element['kind'], ['translate_fwd', 'translate_back', 'listen'], true)) {
        apiError(apiAnswer($learner, $id, $element['id'], ['option_id' => 'oNope']), 422, 'unknown_option_id');
    }
    expect(GameRun::query()->findOrFail($id)->isActive())->toBeTrue();
});

it('sells the hint of the bazaar over HTTP: the hint, the coins left, the same hint for the same element for free', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameWallet::factory()->withBalance(7)->create(['learner_id' => $learner->id]);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $run = GameRun::query()->findOrFail($id);
    $element = GameKit::current($run);

    apiError(apiHint($learner, $id, $element['id']), 409, 'hint_not_available');

    apiBazaarAt($run, 0);
    $view = apiRun($learner, $id)->assertOk();
    expect($view->json('hint'))->toBe(['price' => 5, 'available' => true])->and($view->json('current.event'))->toBe('bazaar');

    $first = apiHint($learner, $id, $element['id'])->assertOk();
    $second = apiHint($learner, $id, $element['id'])->assertOk();
    expect($first->json())->toHaveKeys(['hint', 'coins', 'run'])
        ->and($first->json('coins'))->toBe(['balance' => 2])
        ->and($first->json('hint'))->toBeArray()->and($first->json('hint'))->not->toBe([])
        ->and($second->json('hint'))->toBe($first->json('hint'))->and($second->json('coins'))->toBe(['balance' => 2])
        ->and($first->json('run.current.element.hint'))->toBe($first->json('hint'))
        ->and($first->json('run.hint.available'))->toBeFalse();

    // No coins for the next one.
    $answered = GameKit::rightAnswers($element)[0];
    apiAnswer($learner, $id, $element['id'], $answered)->assertOk();
    $next = GameKit::current($run);
    apiBazaarAt($run->refresh(), 1);
    expect(apiError(apiHint($learner, $id, $next['id']), 409, 'coins_insufficient')['details'])->toBe(['balance' => 2, 'price' => 5]);
});

it('abandons a run on confirmation: finished / abandoned, the learner may start again, a second abandon changes nothing', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');

    $closed = apiAbandon($learner, $id)->assertOk();
    expect($closed->json('status'))->toBe('finished')->and($closed->json('finish_reason'))->toBe('abandoned')
        ->and($closed->json('current'))->toBeNull()->and($closed->json('summary.stars'))->toBeNull();
    apiAbandon($learner, $id)->assertOk()->assertJsonPath('finish_reason', 'abandoned');
    expect(apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id'))->not->toBe($id)
        ->and(ProductEvent::query()->where('name', 'game.run_finished')->count())->toBe(1);
});

it('closes a run past its time: 410 game_run_closed with `expired` on an answer, 200 on a read', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));

    CarbonImmutable::setTestNow('2026-11-05 10:31:00');

    expect(apiError(apiAnswer($learner, $id, $element['id'], ['skip' => true]), 410, 'game_run_closed')['details'])->toBe(['finish_reason' => 'expired']);
    apiRun($learner, $id)->assertOk()->assertJsonPath('finish_reason', 'expired')->assertJsonPath('current', null);
});

it('answers 403 game_unavailable on every route while the game is off, and for a learner outside the pilot', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));

    config(['game.access' => 'off']);

    apiError(apiStart($learner, $course->slug, $lesson->id), 403, 'game_unavailable');
    apiError(apiRun($learner, $id), 403, 'game_unavailable');
    apiError(apiAnswer($learner, $id, $element['id'], ['skip' => true]), 403, 'game_unavailable');
    apiError(apiHint($learner, $id, $element['id']), 403, 'game_unavailable');
    apiError(apiAbandon($learner, $id), 403, 'game_unavailable');
    apiError(apiAudio($learner, $id, $element['id']), 403, 'game_unavailable');
});

it('requires the authorization of the Mini App on every route', function (): void {
    test()->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => 'x', 'lesson_id' => 1])->assertUnauthorized();
    test()->getJson('/api/twa/game/runs/1')->assertUnauthorized();
    test()->postJson('/api/twa/game/runs/1/answer', [])->assertUnauthorized();
    test()->postJson('/api/twa/game/runs/1/hint', [])->assertUnauthorized();
    test()->postJson('/api/twa/game/runs/1/abandon', [])->assertUnauthorized();
    test()->get('/api/twa/game/runs/1/audio/e1')->assertUnauthorized();
});

/** The run in a state where the current element is the first of `$kind` in a fresh run of a rich stop; null when none came. */
function apiRunWith(string $kind, Learner $learner, string $course, int $lessonId): ?GameRun
{
    for ($try = 0; $try < 30; $try++) {
        $id = apiStart($learner, $course, $lessonId, ['replace_active' => true])->assertCreated()->json('id');
        $run = GameRun::query()->findOrFail($id);
        $kinds = array_column($run->snapshot['elements'], 'kind');
        if (in_array($kind, $kinds, true)) {
            return $run;
        }
        apiNewMinute($learner);
    }

    return null;
}

it('streams the clip of an element up to the current one as audio/mpeg, no-store; a later one, a one without a clip and an unknown one are 404', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $run = apiRunWith('listen', $learner, $course->slug, $lesson->id) ?? throw new RuntimeException('No listen task in 30 runs.');
    $elements = $run->snapshot['elements'];
    $at = array_search('listen', array_column($elements, 'kind'), true);
    expect($at)->toBeInt();

    // Play up to the listen task: its clip is handed out when it is the current element, not before.
    $listen = $elements[$at];
    if ($at > 0) {
        apiError(apiAudio($learner, $run->id, $listen['id']), 404, 'game_run_not_found');
    }
    for ($i = 0; $i < $at; $i++) {
        foreach (GameKit::rightAnswers($elements[$i]) as $answer) {
            apiAnswer($learner, $run->id, $elements[$i]['id'], $answer)->assertOk();
        }
        apiNewMinute($learner);
    }

    $view = apiRun($learner, $run->id)->assertOk();
    expect($view->json('current.element.id'))->toBe($listen['id'])
        ->and($view->json('current.element.audio_url'))->toBe("/api/twa/game/runs/{$run->id}/audio/{$listen['id']}");

    $clip = apiAudio($learner, $run->id, $listen['id'])->assertOk();
    expect($clip->headers->get('Content-Type'))->toBe('audio/mpeg')
        ->and($clip->headers->get('Cache-Control'))->toContain('no-store')
        ->and($clip->streamedContent())->toStartWith('mp3:');

    apiError(apiAudio($learner, $run->id, 'eNope'), 404, 'game_run_not_found');
    // A task without a clip (a spell, a translation back, a gap) has none to give.
    foreach ($elements as $element) {
        if (in_array($element['kind'], ['spell', 'translate_back', 'gap'], true)) {
            apiError(apiAudio($learner, $run->id, $element['id']), 404, 'game_run_not_found');

            break;
        }
    }
    // The clip of a later task is not handed out.
    $later = array_values(array_filter(array_slice($elements, $at + 1), static fn (array $e): bool => isset($e['audio_path'])));
    if ($later !== []) {
        apiError(apiAudio($learner, $run->id, $later[0]['id']), 404, 'game_run_not_found');
    }
});

it('answers 503 when the file of the clip is not on the disk', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $run = apiRunWith('listen', $learner, $course->slug, $lesson->id) ?? throw new RuntimeException('No listen task in 30 runs.');
    $elements = $run->snapshot['elements'];
    $listen = $elements[array_search('listen', array_column($elements, 'kind'), true)];
    $at = array_search('listen', array_column($elements, 'kind'), true);
    for ($i = 0; $i < $at; $i++) {
        foreach (GameKit::rightAnswers($elements[$i]) as $answer) {
            apiAnswer($learner, $run->id, $elements[$i]['id'], $answer)->assertOk();
        }
        apiNewMinute($learner);
    }

    Storage::disk($listen['audio_disk'])->delete($listen['audio_path']);

    apiError(apiAudio($learner, $run->id, $listen['id']), 503, 'practice_audio_unavailable');
});

it('streams the clip of a dictation from the disk of the phrases, by the same route', function (): void {
    [$learner, $course, $lesson] = GameKit::richStop();
    $run = apiRunWith('dictation', $learner, $course->slug, $lesson->id) ?? throw new RuntimeException('No dictation in 30 runs.');
    $elements = $run->snapshot['elements'];
    $at = array_search('dictation', array_column($elements, 'kind'), true);
    for ($i = 0; $i < $at; $i++) {
        foreach (GameKit::rightAnswers($elements[$i]) as $answer) {
            apiAnswer($learner, $run->id, $elements[$i]['id'], $answer)->assertOk();
        }
        apiNewMinute($learner);
    }

    $view = apiRun($learner, $run->id)->assertOk();
    $clip = apiAudio($learner, $run->id, $elements[$at]['id'])->assertOk();

    expect($view->json('current.element.audio_url'))->toBe("/api/twa/game/runs/{$run->id}/audio/{$elements[$at]['id']}")
        ->and($clip->headers->get('Content-Type'))->toBe('audio/mpeg')
        ->and($clip->streamedContent())->toBe('mp3:Phrase of the caravan');
});

it('limits the start to 10 a minute per learner and puts the routes behind the limiters the document names', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);

    // A run is on, so every start after the first is the same stop (cheap) - the limiter counts requests, not runs.
    foreach (range(1, 10) as $_) {
        apiStart($learner, $course->slug, $lesson->id)->assertCreated();
    }
    apiStart($learner, $course->slug, $lesson->id)->assertStatus(429)->assertHeader('Retry-After');

    $limiters = static function (string $name): array {
        $route = Route::getRoutes()->getByName($name) ?? throw new RuntimeException("No route {$name}.");

        return array_values(array_filter(array_map('strval', $route->gatherMiddleware()), static fn (string $m): bool => str_starts_with($m, 'throttle:')));
    };
    expect($limiters('twa.game.runs.store'))->toBe(['throttle:twa-api', 'throttle:twa-game-start'])
        ->and($limiters('twa.game.runs.show'))->toBe(['throttle:twa-api'])
        ->and($limiters('twa.game.runs.answer'))->toBe(['throttle:twa-api', 'throttle:twa-game-answer'])
        ->and($limiters('twa.game.runs.hint'))->toBe(['throttle:twa-api', 'throttle:twa-game-answer'])
        ->and($limiters('twa.game.runs.abandon'))->toBe(['throttle:twa-api'])
        ->and($limiters('twa.game.runs.audio'))->toBe(['throttle:twa-practice-audio'])
        ->and(route('twa.game.runs.audio', ['run' => 9, 'element' => 'eAbc'], false))->toBe('/api/twa/game/runs/9/audio/eAbc');

    // The bucket of the answers is 120 a minute per learner (and `twa-api` still caps a minute at 60 requests).
    $request = Request::create('/');
    $request->attributes->set('twa', ['learner_id' => 5]);
    $limit = RateLimiter::limiter('twa-game-answer')($request);
    expect($limit->maxAttempts)->toBe(120)->and($limit->key)->toBe('twa-game-answer|5');
});

it('stops the answers of a learner at the 61st request of a minute (twa-api), with Retry-After', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $id = apiStart($learner, $course->slug, $lesson->id)->assertCreated()->json('id');
    $element = GameKit::current(GameRun::query()->findOrFail($id));

    foreach (range(1, 58) as $_) { // the start was the first request
        apiAnswer($learner, $id, 'eNope', ['skip' => true])->assertStatus(422);
    }
    apiAnswer($learner, $id, $element['id'], ['skip' => true])->assertOk();
    apiAnswer($learner, $id, 'eNope', ['skip' => true])->assertStatus(429)->assertHeader('Retry-After');
});
