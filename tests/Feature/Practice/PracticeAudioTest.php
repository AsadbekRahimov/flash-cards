<?php

declare(strict_types=1);

use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Models\Learner;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\DictationKit;
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §7.2, §10.2, §10.5: the audio of a dictation is streamed by the opaque id of the element, from the disk the element
 * of the snapshot names; only to its owner; `private, no-store`; the path never reaches a client. The first tests stream the
 * clips of a test handler from faked disks; the last ones play the REAL dictation (18.5): a phrase is streamed from the private
 * disk `practice_audio`, a word from the disk of the words, and neither a path nor a text is in any JSON before the answer. The
 * voicing itself (the job, the disk config) is GeneratePracticeAudioJobTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
    Storage::fake(FakeEchoMode::AUDIO_DISK);
    Storage::fake('public');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{0: Learner, 1: array<string, mixed>} a learner and the response body of an opened dictation session */
function practiceClipSession(): array
{
    PracticeKit::fake(PracticeMode::Dictation, candidates: 5);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $session = test()->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug])
        ->assertCreated()->json();

    return [$learner, $session];
}

function practiceClipGet(Learner $learner, string $url): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->get($url);
}

/** The audio path the snapshot of the session names for an element. */
function practiceClipPath(int $sessionId, string $elementId): string
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element['audio_path'];
        }
    }

    throw new RuntimeException('No such element.');
}

it('streams the file of the element to its owner: audio/mpeg, private and no-store, with the length', function (): void {
    [$learner, $session] = practiceClipSession();
    $element = $session['current']['id'];
    $bytes = random_bytes(200_000);
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $element), $bytes);

    $response = practiceClipGet($learner, $session['current']['audio_url'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('audio/mpeg')
        ->and($response->headers->get('Content-Length'))->toBe('200000')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe($bytes);
});

it('streams the audio of a word from the public disk when the element names that disk', function (): void {
    [$learner, $session] = practiceClipSession();
    $element = $session['current']['id'];
    $row = PracticeSession::query()->findOrFail($session['id']);
    $snapshot = $row->snapshot;
    $snapshot['elements'][0]['audio_disk'] = 'public';
    $snapshot['elements'][0]['audio_path'] = 'audio/'.sha1('word').'.mp3';
    $row->update(['snapshot' => $snapshot]);
    Storage::disk('public')->put('audio/'.sha1('word').'.mp3', 'word-mp3');

    expect(practiceClipGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent())->toBe('word-mp3');
    expect($element)->toBe($snapshot['elements'][0]['id']);
});

it('never lets the path, the disk or the text of the dictation out of the server: the element carries only the URL', function (): void {
    [, $session] = practiceClipSession();

    $json = json_encode($session['current'], JSON_THROW_ON_ERROR);

    expect($session['current'])->toHaveKeys(['id', 'kind', 'audio_url', 'replacements_left'])
        ->and($session['current'])->not->toHaveKeys(['text', 'prompt', 'expected', 'accepted', 'audio_path', 'audio_disk', 'display', 'translation'])
        ->and($json)->not->toContain('clip-')->not->toContain('.mp3')->not->toContain('practice_audio')
        ->and($session['current']['audio_url'])->toBe("/api/twa/practice/sessions/{$session['id']}/audio/{$session['current']['id']}");
});

it('answers 503 practice_audio_unavailable when the file is not there or is empty, and notes it', function (): void {
    [$learner, $session] = practiceClipSession();
    $element = $session['current']['id'];

    practiceClipGet($learner, $session['current']['audio_url'])->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');

    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $element), '');
    practiceClipGet($learner, $session['current']['audio_url'])->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');

    $event = ProductEvent::query()->where('name', 'practice.audio_failed')->orderBy('id')->firstOrFail();
    expect(ProductEvent::query()->where('name', 'practice.audio_failed')->count())->toBe(2)
        ->and($event->learner_id)->toBe($learner->id)
        ->and($event->props)->toMatchArray(['session_id' => $session['id'], 'source' => 'phrase', 'reason' => 'file_missing', 'verified' => true]);

    // The learner is not stuck: the swap of the element is there (and a file that appears later plays).
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $element), 'now-there');
    expect(practiceClipGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent())->toBe('now-there');
});

it('hides the audio of somebody else\'s session, of a missing one and of an element nobody knows behind the same 404', function (): void {
    [$learner, $session] = practiceClipSession();
    $element = $session['current']['id'];
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $element), 'mp3');
    $stranger = Learner::factory()->create();

    practiceClipGet($stranger, "/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    practiceClipGet($learner, '/api/twa/practice/sessions/999999/audio/'.$element)->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/eNoSuchEl00")->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    test()->flushHeaders()->get("/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertUnauthorized();
});

it('has no audio for an element without it, and follows no disk but the two it knows', function (): void {
    [$learner, $session] = practiceClipSession();
    $element = $session['current']['id'];
    $row = PracticeSession::query()->findOrFail($session['id']);
    $snapshot = $row->snapshot;

    $snapshot['elements'][0]['audio_disk'] = 'local';
    $row->update(['snapshot' => $snapshot]);
    Storage::fake('local')->put($snapshot['elements'][0]['audio_path'], 'secret');
    practiceClipGet($learner, $session['current']['audio_url'])->assertNotFound();

    unset($snapshot['elements'][0]['audio_disk'], $snapshot['elements'][0]['audio_path']);
    $row->update(['snapshot' => $snapshot]);
    practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertNotFound();

    $service = app(PracticeAudioService::class);
    expect($service->clipFor(Snapshot::fromArray($row->fresh()?->snapshot ?? []), 'eNoSuchEl00'))->toBeNull()
        ->and($service->clipFor(Snapshot::fromArray($row->fresh()?->snapshot ?? []), $element))->toBeNull();
});

it('lets a finished session be listened to again until its expires_at, and not after', function (): void {
    PracticeKit::fake(PracticeMode::Dictation, candidates: 3);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $headers = PracticeKit::headers($learner);
    $session = test()->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug])->assertCreated()->json();
    $first = $session['current'];
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $first['id']), 'mp3');

    // Finish the session (a skip each).
    for ($i = 0; $i < 3; $i++) {
        $session = test()->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(),
            'element_id' => $session['current']['id'],
            'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    expect($session['status'])->toBe('finished');

    practiceClipGet($learner, $first['audio_url'])->assertOk();

    CarbonImmutable::setTestNow('2026-10-31 10:00:01');
    practiceClipGet($learner, $first['audio_url'])->assertNotFound();
});

it('costs two queries beyond the file itself: the learner and the session', function (): void {
    [$learner, $session] = practiceClipSession();
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(practiceClipPath($session['id'], $session['current']['id']), 'mp3');

    $queries = QueryLog::during(fn () => practiceClipGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent());

    expect($queries)->toHaveCount(2);
});

// ------------------------------------------------------------------ the real dictation (18.5)

/**
 * A session of the real dictation: 2 words and 3 phrases, every clip on its own disk.
 *
 * @return array{0: Learner, 1: array<string, mixed>, 2: list<array<string, mixed>>} the learner, the opened session, the elements of the snapshot
 */
function practiceRealDictation(): array
{
    [$learner, $course, $lesson] = DictationKit::world();
    DictationKit::stock($lesson, $learner, 5, 6);
    $session = test()->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug, 'lesson_id' => $lesson->id])
        ->assertCreated()->json();

    return [$learner, $session, PracticeSession::query()->findOrFail($session['id'])->snapshot['elements']];
}

it('streams a phrase from the private disk practice_audio and a word from the disk of the words', function (): void {
    [$learner, $session, $elements] = practiceRealDictation();

    expect(array_column($elements, 'audio_disk'))->toBe(['public', 'public', 'practice_audio', 'practice_audio', 'practice_audio']);
    foreach ($elements as $element) {
        $url = "/api/twa/practice/sessions/{$session['id']}/audio/{$element['id']}";
        $response = practiceClipGet($learner, $url)->assertOk();

        // The kit writes "mp3:<the text>" into the file of each element - on the disk the element names, and only there.
        expect($response->streamedContent())->toBe('mp3:'.$element['display'])
            ->and($response->headers->get('Content-Type'))->toBe('audio/mpeg')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and(Storage::disk($element['audio_disk'])->exists($element['audio_path']))->toBeTrue()
            ->and(Storage::disk($element['audio_disk'] === 'public' ? 'practice_audio' : 'public')->exists($element['audio_path']))->toBeFalse();
    }
});

it('says 503 for a word whose clip is gone from the disk of the words, and for a phrase whose clip is gone from practice_audio', function (): void {
    [$learner, $session, $elements] = practiceRealDictation();
    Storage::disk('public')->delete($elements[0]['audio_path']);
    Storage::disk('practice_audio')->delete($elements[2]['audio_path']);

    foreach ([0, 2] as $i) {
        practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$elements[$i]['id']}")
            ->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');
    }
    practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$elements[1]['id']}")->assertOk();

    $events = ProductEvent::query()->where('name', 'practice.audio_failed')->orderBy('id')->get();
    expect($events->map(fn (ProductEvent $event) => $event->props['source'] ?? null)->all())->toBe(['word', 'phrase']);
});

it('puts neither the path, nor the disk, nor the text of any clip into the JSON of the session, whatever the request', function (): void {
    [$learner, $session, $elements] = practiceRealDictation();
    $headers = PracticeKit::headers($learner);
    $views = [$session, test()->withHeaders($headers)->getJson("/api/twa/practice/sessions/{$session['id']}")->assertOk()->json()];

    // Every answer returns the session with the NEXT element; the review of the answered one is the only place a text may be.
    $reviews = [];
    foreach ($elements as $element) {
        $answer = test()->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(),
            'element_id' => $element['id'],
            'answer' => ['text' => 'nonsense'],
        ])->assertOk();
        $views[] = $answer->json('session');
        $reviews[] = [$element, $answer->json('reveal')];
    }

    foreach ($views as $view) {
        $json = (string) json_encode($view, JSON_THROW_ON_ERROR);
        foreach ($elements as $element) {
            expect($json)->not->toContain($element['audio_path']);
            // The summary of a finished session lists the mistakes by their text; a session that is on shows none.
            if ($view['status'] === 'active') {
                expect($json)->not->toContain($element['display']);
            }
        }
        expect($json)->not->toContain('.mp3')->not->toContain('practice_audio')->not->toContain('audio_path')->not->toContain('audio_disk');
    }
    foreach ($reviews as [$element, $review]) {
        // The review shows the text of the element that was answered, and no file.
        expect($review['expected'])->toBe($element['display'])
            ->and((string) json_encode($review, JSON_THROW_ON_ERROR))->not->toContain('.mp3')->not->toContain($element['audio_path']);
    }
});

it('lets the learner listen again in the review: the clip of an answered element is streamed while the session lasts', function (): void {
    [$learner, $session, $elements] = practiceRealDictation();
    foreach ($elements as $element) {
        test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $element['id'], 'answer' => ['skip' => true],
        ])->assertOk();
    }

    foreach ($elements as $element) {
        practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$element['id']}")->assertOk();
    }
});

it('does not serve the clip of somebody else\'s dictation, or of an element the swap has taken out', function (): void {
    [$learner, $session, $elements] = practiceRealDictation();
    $stranger = Learner::factory()->create();

    practiceClipGet($stranger, "/api/twa/practice/sessions/{$session['id']}/audio/{$elements[0]['id']}")->assertNotFound();

    test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/replace", [
        'request_id' => (string) Str::uuid(), 'element_id' => $elements[0]['id'], 'reason' => 'audio_unavailable',
    ])->assertOk();
    practiceClipGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$elements[0]['id']}")->assertNotFound();
});
