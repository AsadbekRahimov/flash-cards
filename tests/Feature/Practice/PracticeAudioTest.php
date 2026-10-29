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
use Tests\Support\Practice\FakeEchoMode;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/16 §7.2, §10.2, §10.5: the audio of a dictation is streamed by the opaque id of the element, from the disk the element
 * of the snapshot names; only to its owner; `private, no-store`; the path never reaches a client. The generation of the audio
 * (the disk `practice_audio` in config/filesystems.php, the job) is 18.5: here the disks are faked.
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
function audioSession(): array
{
    PracticeKit::fake(PracticeMode::Dictation, candidates: 5);
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $session = test()->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'dictation', 'course' => $course->slug])
        ->assertCreated()->json();

    return [$learner, $session];
}

function audioGet(Learner $learner, string $url): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->get($url);
}

/** The audio path the snapshot of the session names for an element. */
function audioPathOf(int $sessionId, string $elementId): string
{
    foreach (PracticeSession::query()->findOrFail($sessionId)->snapshot['elements'] as $element) {
        if ($element['id'] === $elementId) {
            return $element['audio_path'];
        }
    }

    throw new RuntimeException('No such element.');
}

it('streams the file of the element to its owner: audio/mpeg, private and no-store, with the length', function (): void {
    [$learner, $session] = audioSession();
    $element = $session['current']['id'];
    $bytes = random_bytes(200_000);
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $element), $bytes);

    $response = audioGet($learner, $session['current']['audio_url'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('audio/mpeg')
        ->and($response->headers->get('Content-Length'))->toBe('200000')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe($bytes);
});

it('streams the audio of a word from the public disk when the element names that disk', function (): void {
    [$learner, $session] = audioSession();
    $element = $session['current']['id'];
    $row = PracticeSession::query()->findOrFail($session['id']);
    $snapshot = $row->snapshot;
    $snapshot['elements'][0]['audio_disk'] = 'public';
    $snapshot['elements'][0]['audio_path'] = 'audio/'.sha1('word').'.mp3';
    $row->update(['snapshot' => $snapshot]);
    Storage::disk('public')->put('audio/'.sha1('word').'.mp3', 'word-mp3');

    expect(audioGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent())->toBe('word-mp3');
    expect($element)->toBe($snapshot['elements'][0]['id']);
});

it('never lets the path, the disk or the text of the dictation out of the server: the element carries only the URL', function (): void {
    [, $session] = audioSession();

    $json = json_encode($session['current'], JSON_THROW_ON_ERROR);

    expect($session['current'])->toHaveKeys(['id', 'kind', 'audio_url', 'replacements_left'])
        ->and($session['current'])->not->toHaveKeys(['text', 'prompt', 'expected', 'accepted', 'audio_path', 'audio_disk', 'display', 'translation'])
        ->and($json)->not->toContain('clip-')->not->toContain('.mp3')->not->toContain('practice_audio')
        ->and($session['current']['audio_url'])->toBe("/api/twa/practice/sessions/{$session['id']}/audio/{$session['current']['id']}");
});

it('answers 503 practice_audio_unavailable when the file is not there or is empty, and notes it', function (): void {
    [$learner, $session] = audioSession();
    $element = $session['current']['id'];

    audioGet($learner, $session['current']['audio_url'])->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');

    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $element), '');
    audioGet($learner, $session['current']['audio_url'])->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');

    $event = ProductEvent::query()->where('name', 'practice.audio_failed')->orderBy('id')->firstOrFail();
    expect(ProductEvent::query()->where('name', 'practice.audio_failed')->count())->toBe(2)
        ->and($event->learner_id)->toBe($learner->id)
        ->and($event->props)->toMatchArray(['session_id' => $session['id'], 'source' => 'phrase', 'reason' => 'file_missing', 'verified' => true]);

    // The learner is not stuck: the swap of the element is there (and a file that appears later plays).
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $element), 'now-there');
    expect(audioGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent())->toBe('now-there');
});

it('hides the audio of somebody else\'s session, of a missing one and of an element nobody knows behind the same 404', function (): void {
    [$learner, $session] = audioSession();
    $element = $session['current']['id'];
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $element), 'mp3');
    $stranger = Learner::factory()->create();

    audioGet($stranger, "/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    audioGet($learner, '/api/twa/practice/sessions/999999/audio/'.$element)->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    audioGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/eNoSuchEl00")->assertNotFound()->assertJsonPath('error.code', 'practice_session_not_found');
    test()->flushHeaders()->get("/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertUnauthorized();
});

it('has no audio for an element without it, and follows no disk but the two it knows', function (): void {
    [$learner, $session] = audioSession();
    $element = $session['current']['id'];
    $row = PracticeSession::query()->findOrFail($session['id']);
    $snapshot = $row->snapshot;

    $snapshot['elements'][0]['audio_disk'] = 'local';
    $row->update(['snapshot' => $snapshot]);
    Storage::fake('local')->put($snapshot['elements'][0]['audio_path'], 'secret');
    audioGet($learner, $session['current']['audio_url'])->assertNotFound();

    unset($snapshot['elements'][0]['audio_disk'], $snapshot['elements'][0]['audio_path']);
    $row->update(['snapshot' => $snapshot]);
    audioGet($learner, "/api/twa/practice/sessions/{$session['id']}/audio/{$element}")->assertNotFound();

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
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $first['id']), 'mp3');

    // Finish the session (a skip each).
    for ($i = 0; $i < 3; $i++) {
        $session = test()->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(),
            'element_id' => $session['current']['id'],
            'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    expect($session['status'])->toBe('finished');

    audioGet($learner, $first['audio_url'])->assertOk();

    CarbonImmutable::setTestNow('2026-10-31 10:00:01');
    audioGet($learner, $first['audio_url'])->assertNotFound();
});

it('costs two queries beyond the file itself: the learner and the session', function (): void {
    [$learner, $session] = audioSession();
    Storage::disk(FakeEchoMode::AUDIO_DISK)->put(audioPathOf($session['id'], $session['current']['id']), 'mp3');

    $queries = QueryLog::during(fn () => audioGet($learner, $session['current']['audio_url'])->assertOk()->streamedContent());

    expect($queries)->toHaveCount(2);
});
