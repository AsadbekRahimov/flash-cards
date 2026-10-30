<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\PracticeItemVersion;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * docs/16 §10.1: the voice of a dictation phrase. The job asks the TTS service for the text of the version, writes
 * `{audio_key}.mp3` on the disk `practice_audio` atomically and sets `audio_status` (`ready`; `failed` after the last attempt);
 * a repeat changes nothing. The TTS service is faked: no test talks to the real one.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.tts.service_url' => 'http://tts.test', 'twa.tts.voice' => 'en-US-AriaNeural', 'twa.tts.audio_version' => 1]);
    Storage::fake('practice_audio');
});

/** What the TTS service answers with: some bytes of an mp3. A new call forgets the replies and the record of the calls before it. */
function audioJobFakeTts(string $body = 'mp3-bytes', int $status = 200, string $type = 'audio/mpeg'): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(['http://tts.test/tts' => Http::response($body, $status, ['Content-Type' => $type])]);
}

/** A dictation version that waits for its voice. */
function audioJobVersion(string $text = 'See you tomorrow'): PracticeItemVersion
{
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    $version->forceFill(['content' => [...$version->content, 'text' => $text, 'accepted' => [$text]]])->save();

    return $version->fresh() ?? $version;
}

function audioJobKey(PracticeItemVersion $version, string $normalized = 'see you tomorrow', string $voice = 'en-US-AriaNeural', int $audioVersion = 1): string
{
    return sha1("{$version->id}|{$normalized}|{$voice}|{$audioVersion}");
}

function audioJobRun(PracticeItemVersion $version, bool $force = false): void
{
    (new GeneratePracticeAudioJob($version->id, $force))->handle(app(PracticeAudioService::class));
}

it('voices the text of the version: the file {audio_key}.mp3 on the disk practice_audio, the version is ready', function (): void {
    audioJobFakeTts('the-mp3');
    $version = audioJobVersion();

    audioJobRun($version);

    $key = audioJobKey($version);
    $fresh = $version->fresh();
    expect($fresh?->audio_status)->toBe(AudioStatus::Ready)
        ->and($fresh?->audio_key)->toBe($key)
        ->and($fresh?->audio_error)->toBeNull()
        ->and($fresh?->audio_generated_at)->not->toBeNull();
    Storage::disk('practice_audio')->assertExists("{$key}.mp3");
    expect(Storage::disk('practice_audio')->get("{$key}.mp3"))->toBe('the-mp3');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['word'] === 'See you tomorrow' && $request['voice'] === 'en-US-AriaNeural');
});

it('names the file by a hash that carries no text, and changes the name with the voice, the audio version and the version of the task', function (): void {
    audioJobFakeTts();
    $version = audioJobVersion('Where is the STATION?');

    audioJobRun($version);
    $first = (string) $version->fresh()?->audio_key;

    expect($first)->toBe(audioJobKey($version, 'where is the station'))->toMatch('/\A[0-9a-f]{40}\z/')
        ->not->toContain('station');

    // Another voice is another file; the old one stays where it was.
    config(['twa.tts.voice' => 'en-GB-SoniaNeural']);
    audioJobRun($version);
    $second = (string) $version->fresh()?->audio_key;
    expect($second)->not->toBe($first)->and($second)->toBe(audioJobKey($version, 'where is the station', 'en-GB-SoniaNeural'));
    Storage::disk('practice_audio')->assertExists(["{$first}.mp3", "{$second}.mp3"]);

    // So is a bumped TWA audio version.
    config(['twa.tts.audio_version' => 2]);
    audioJobRun($version);
    expect($version->fresh()?->audio_key)->toBe(audioJobKey($version, 'where is the station', 'en-GB-SoniaNeural', 2));

    // And the same text of another version of the task.
    $other = audioJobVersion('Where is the STATION?');
    audioJobRun($other);
    expect($other->fresh()?->audio_key)->not->toBe($first);
});

it('is idempotent: a repeat of the job makes no TTS call and leaves the file and the row as they are', function (): void {
    audioJobFakeTts('first');
    $version = audioJobVersion();

    audioJobRun($version);
    $generatedAt = $version->fresh()?->audio_generated_at;
    audioJobFakeTts('second');
    audioJobRun($version->fresh() ?? $version);

    $key = audioJobKey($version);
    Http::assertNothingSent();
    expect(Storage::disk('practice_audio')->get("{$key}.mp3"))->toBe('first')
        ->and($version->fresh()?->audio_status)->toBe(AudioStatus::Ready)
        ->and(Storage::disk('practice_audio')->allFiles())->toBe(["{$key}.mp3"]);
    expect($generatedAt)->not->toBeNull();
});

it('catches the row up with a file that is already there, without a TTS call (a crash between the file and the row)', function (): void {
    audioJobFakeTts('would-be-new');
    $version = audioJobVersion();
    Storage::disk('practice_audio')->put(audioJobKey($version).'.mp3', 'the-voice-of-an-earlier-run');

    audioJobRun($version);

    Http::assertNothingSent();
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Ready)
        ->and($version->fresh()?->audio_key)->toBe(audioJobKey($version))
        ->and(Storage::disk('practice_audio')->get(audioJobKey($version).'.mp3'))->toBe('the-voice-of-an-earlier-run');
});

it('counts an empty leftover as not generated and voices the text again', function (): void {
    audioJobFakeTts('whole-mp3');
    $version = audioJobVersion();
    Storage::disk('practice_audio')->put(audioJobKey($version).'.mp3', '');

    audioJobRun($version);

    Http::assertSentCount(1);
    expect(Storage::disk('practice_audio')->get(audioJobKey($version).'.mp3'))->toBe('whole-mp3');
});

it('voices the text again when forced (the admin action "generate again"), replacing the file', function (): void {
    audioJobFakeTts('old');
    $version = audioJobVersion();
    audioJobRun($version);

    audioJobFakeTts('new');
    audioJobRun($version->fresh() ?? $version, force: true);

    Http::assertSentCount(1); // the second fake replaced the first: one call since
    expect(Storage::disk('practice_audio')->get(audioJobKey($version).'.mp3'))->toBe('new')
        ->and($version->fresh()?->audio_status)->toBe(AudioStatus::Ready);
});

it('leaves no half-written file or temporary leftover behind', function (): void {
    audioJobFakeTts();
    $version = audioJobVersion();

    audioJobRun($version);

    $files = Storage::disk('practice_audio')->allFiles();
    expect($files)->toBe([audioJobKey($version).'.mp3'])
        ->and(array_filter($files, fn (string $file): bool => str_contains($file, '.tmp')))->toBe([]);
});

it('writes under practice.audio.dir when the config names one', function (): void {
    config(['practice.audio.dir' => 'clips/']);
    audioJobFakeTts();
    $version = audioJobVersion();

    audioJobRun($version);

    Storage::disk('practice_audio')->assertExists('clips/'.audioJobKey($version).'.mp3');
    expect(app(PracticeAudioService::class)->pathFor($version->fresh() ?? $version))->toBe('clips/'.audioJobKey($version).'.mp3');
});

it('retries a failed TTS call: the job throws and the version stays pending until the last attempt', function (): void {
    audioJobFakeTts('', 500);
    $version = audioJobVersion();

    expect(fn () => audioJobRun($version))->toThrow(RuntimeException::class, 'tts_service_failed');

    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Pending)
        ->and(Storage::disk('practice_audio')->allFiles())->toBe([]);
});

it('turns the version to failed with the reason after the last attempt, and a later run recovers it', function (): void {
    Log::spy();
    audioJobFakeTts('', 503);
    $version = audioJobVersion();
    $job = new GeneratePracticeAudioJob($version->id);

    try {
        $job->handle(app(PracticeAudioService::class));
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $failed = $version->fresh();
    expect($failed?->audio_status)->toBe(AudioStatus::Failed)
        ->and($failed?->audio_error)->toBe('tts_service_failed');
    Log::shouldHaveReceived('warning')->once()->with('practice.audio_failed', ['version_id' => $version->id, 'reason' => 'tts_service_failed']);

    // "Generate again": scheduled -> pending -> the service is back -> ready, the error is gone.
    Queue::fake();
    app(PracticeAudioService::class)->schedule($failed ?? $version);
    Queue::assertPushed(GeneratePracticeAudioJob::class, fn (GeneratePracticeAudioJob $queued): bool => $queued->versionId === $version->id);
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Pending);
    audioJobFakeTts('recovered');
    audioJobRun($version);
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Ready)
        ->and($version->fresh()?->audio_error)->toBeNull();
});

it('runs through the queue: the failure of the last attempt reaches failed() on its own', function (): void {
    config(['queue.default' => 'sync']);
    audioJobFakeTts('', 500);
    $version = audioJobVersion();

    // The sync queue has no retries: its one attempt is the last one.
    try {
        GeneratePracticeAudioJob::dispatch($version->id);
    } catch (RuntimeException) {
        // the sync queue rethrows what the job threw
    }

    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Failed)
        ->and($version->fresh()?->audio_error)->toBe('tts_service_failed');
});

it('keeps a version that another run has voiced in the meantime ready, and leaves the other states alone', function (): void {
    $service = app(PracticeAudioService::class);
    $reason = new RuntimeException('tts_service_failed');

    $ready = PracticeItemVersion::factory()->dictation()->audioReady()->create();
    $none = PracticeItemVersion::factory()->collocation()->create();
    $failedBefore = PracticeItemVersion::factory()->dictation()->audioFailed('earlier')->create();

    foreach ([$ready, $none, $failedBefore] as $version) {
        $service->markFailed($version->id, $reason);
    }

    expect($ready->fresh()?->audio_status)->toBe(AudioStatus::Ready)
        ->and($none->fresh()?->audio_status)->toBe(AudioStatus::None)
        ->and($failedBefore->fresh()?->audio_error)->toBe('earlier');
});

it('writes a short code as the reason - the class of an unexpected exception, never its message with a URL', function (): void {
    $version = audioJobVersion();

    app(PracticeAudioService::class)->markFailed($version->id, new ConnectionException('cURL error 7: Failed to connect to http://tts:8000/tts?token=secret'));

    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Failed)
        ->and($version->fresh()?->audio_error)->toBe('ConnectionException');
});

it('refuses a reply that is not audio: an empty body, a web page', function (string $body, string $type): void {
    audioJobFakeTts($body, 200, $type);
    $version = audioJobVersion();

    expect(fn () => audioJobRun($version))->toThrow(RuntimeException::class, 'tts_service_bad_audio');

    expect(Storage::disk('practice_audio')->allFiles())->toBe([])
        ->and($version->fresh()?->audio_status)->toBe(AudioStatus::Pending);
})->with([
    'an empty body' => ['', 'audio/mpeg'],
    'a web page' => ['<html>502</html>', 'text/html'],
]);

it('fails at once, without a call, a version with no text to voice, and does nothing for a task that is not a dictation', function (): void {
    audioJobFakeTts();
    $empty = audioJobVersion();
    $empty->forceFill(['content' => ['text' => '   ', 'accepted' => []]])->save();
    $collocation = PracticeItemVersion::factory()->collocation()->create();

    audioJobRun($empty->fresh() ?? $empty);
    app(PracticeAudioService::class)->generate($collocation);

    Http::assertNothingSent();
    expect($empty->fresh()?->audio_status)->toBe(AudioStatus::Failed)
        ->and($empty->fresh()?->audio_error)->toBe('no_text')
        ->and($collocation->fresh()?->audio_status)->toBe(AudioStatus::None)
        ->and($collocation->fresh()?->audio_key)->toBeNull();
});

it('fails at once when the TTS service is not configured: the queue job is failed, not released, and nothing is thrown', function (): void {
    config(['twa.tts.service_url' => '']);
    $version = audioJobVersion();
    $queueJob = Mockery::mock(QueueJob::class);
    // `fail()` once, with the code; no `release()` (a retry) - an installation without a TTS service does not get one in an hour.
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(fn (mixed $e): bool => $e instanceof RuntimeException && $e->getMessage() === 'tts_service_not_configured'));
    $queueJob->shouldNotReceive('release');
    $job = new GeneratePracticeAudioJob($version->id);
    $job->setJob($queueJob);

    $job->handle(app(PracticeAudioService::class));

    Http::assertNothingSent();
});

it('turns the version to failed with the code on the sync queue and throws nothing out of dispatch(): the case of db:seed without a TTS service', function (): void {
    Log::spy();
    config(['twa.tts.service_url' => '', 'queue.default' => 'sync']);
    $version = audioJobVersion();

    // The sync queue rethrows whatever a job throws; this job throws nothing.
    GeneratePracticeAudioJob::dispatch($version->id);

    $failed = $version->fresh();
    expect($failed?->audio_status)->toBe(AudioStatus::Failed)
        ->and($failed?->audio_error)->toBe('tts_service_not_configured')
        ->and(Storage::disk('practice_audio')->allFiles())->toBe([]);
    Log::shouldHaveReceived('warning')->once()->with('practice.audio_failed', ['version_id' => $version->id, 'reason' => 'tts_service_not_configured']);

    // The service comes: "generate again" voices the version.
    config(['twa.tts.service_url' => 'http://tts.test']);
    audioJobFakeTts('recovered');
    app(PracticeAudioService::class)->schedule($failed ?? $version, force: true);
    expect($version->fresh()?->audio_status)->toBe(AudioStatus::Ready)
        ->and($version->fresh()?->audio_error)->toBeNull();
});

it('still throws for a TTS service that is configured and failing: that one is retried', function (): void {
    audioJobFakeTts('', 500);
    $version = audioJobVersion();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldNotReceive('fail');
    $job = new GeneratePracticeAudioJob($version->id);
    $job->setJob($queueJob);

    expect(fn () => $job->handle(app(PracticeAudioService::class)))->toThrow(RuntimeException::class, 'tts_service_failed');
});

it('does nothing for a version that has been deleted since the job was queued', function (): void {
    audioJobFakeTts();

    (new GeneratePracticeAudioJob(999_999))->handle(app(PracticeAudioService::class));

    Http::assertNothingSent();
});

it('says a version is ready only with the status AND the file', function (): void {
    $service = app(PracticeAudioService::class);
    $version = PracticeItemVersion::factory()->dictation()->audioReady()->create();
    $path = (string) $service->pathFor($version);

    expect($service->isReady($version))->toBeFalse(); // ready in the row, no file

    Storage::disk('practice_audio')->put($path, '');
    expect($service->isReady($version))->toBeFalse(); // an empty file

    Storage::disk('practice_audio')->put($path, 'mp3');
    expect($service->isReady($version))->toBeTrue();

    $version->forceFill(['audio_status' => AudioStatus::Pending])->save();
    expect($service->isReady($version))->toBeFalse() // the file, but the row says it is being voiced
        ->and($service->pathFor(PracticeItemVersion::factory()->collocation()->make()))->toBeNull();
});

it('is the job of the low queue with five attempts and a backoff', function (): void {
    $job = new GeneratePracticeAudioJob(1);

    expect($job->queue)->toBe('low')
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([30, 120, 600, 1800]);
});

it('keeps the audio disk private: its own directory, no URL, no link, and no web server maps it', function (): void {
    $disk = config('filesystems.disks.practice_audio');

    expect(config('practice.audio.disk'))->toBe('practice_audio')
        ->and($disk['driver'])->toBe('local')
        ->and($disk['root'])->toBe(storage_path('app/practice-audio'))
        ->and($disk)->not->toHaveKey('url')
        ->and(array_values(config('filesystems.links')))->not->toContain(storage_path('app/practice-audio'));

    foreach (glob(base_path('docker/nginx/*.conf')) ?: [] as $conf) {
        expect(file_get_contents($conf))->not->toContain('practice-audio')->not->toContain('practice_audio');
    }

    // The directory is in the repository only as a placeholder: the voices are never committed.
    expect(file_get_contents(base_path('storage/app/practice-audio/.gitignore')))->toContain('*');
});
