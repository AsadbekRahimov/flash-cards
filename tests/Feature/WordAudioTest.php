<?php

declare(strict_types=1);

use App\Domain\Content\Services\WordAudioService;
use App\Domain\Twa\Services\JwtService;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Storage::fake('public');
});

function audioWord(string $text, ?Course $course = null, array $attrs = []): Word
{
    $stage = Stage::factory()->create($course !== null ? ['course_id' => $course->id] : []);
    $lesson = Lesson::factory()->for($stage)->create();

    return Word::factory()->for($lesson)->create(['word' => $text] + $attrs);
}

function audioPathOf(string $text, ?string $voice = null): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|'.($voice ?? 'en-US-AriaNeural').'|1').'.mp3';
}

function fakeTts(): void
{
    Http::fake(['http://tts.test/tts' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);
}

it('job synthesizes the word, stores audio/{sha1}.mp3 and sets audio_path', function (): void {
    fakeTts();
    $word = audioWord('Resilient');

    (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class));

    $path = audioPathOf('resilient');
    expect($word->fresh()?->audio_path)->toBe($path);
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe('mp3-bytes');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['word'] === 'resilient' && $request['voice'] === 'en-US-AriaNeural');
});

it('job runs on the low queue', function (): void {
    expect((new PregenerateWordAudioJob(1))->queue)->toBe('low');
});

it('is a no-op when audio_path is set and the file exists', function (): void {
    fakeTts();
    $path = audioPathOf('resilient');
    Storage::disk('public')->put($path, 'old');
    $word = audioWord('resilient', attrs: ['audio_path' => $path]);

    (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class));

    Http::assertNothingSent();
    expect(Storage::disk('public')->get($path))->toBe('old');
});

it('regenerates when audio_path is set but the file is gone', function (): void {
    fakeTts();
    $word = audioWord('resilient', attrs: ['audio_path' => audioPathOf('resilient')]);

    (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class));

    Http::assertSentCount(1);
    Storage::disk('public')->assertExists(audioPathOf('resilient'));
});

it('--force style job overwrites an existing file', function (): void {
    fakeTts();
    $path = audioPathOf('resilient');
    Storage::disk('public')->put($path, 'old');
    $word = audioWord('resilient', attrs: ['audio_path' => $path]);

    (new PregenerateWordAudioJob($word->id, force: true))->handle(app(WordAudioService::class));

    expect(Storage::disk('public')->get($path))->toBe('mp3-bytes');
});

it('words with the same text share one file and one TTS call', function (): void {
    fakeTts();
    $a = audioWord('Resilient');
    $b = audioWord('resilient  ');

    $service = app(WordAudioService::class);
    (new PregenerateWordAudioJob($a->id))->handle($service);
    (new PregenerateWordAudioJob($b->id))->handle($service);

    expect($a->fresh()?->audio_path)->toBe($b->fresh()?->audio_path)
        ->and(Storage::disk('public')->allFiles('audio'))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('a changed text or voice version points to a new file', function (): void {
    fakeTts();
    $word = audioWord('resilient');
    $service = app(WordAudioService::class);
    $service->generate($word);
    $first = $word->fresh()?->audio_path;

    Config::set('twa.tts.audio_version', 2);
    $service->generate($word->fresh() ?? $word);

    expect($word->fresh()?->audio_path)->not->toBe($first)
        ->and(Storage::disk('public')->allFiles('audio'))->toHaveCount(2);
});

it('a TTS service error throws for the retry and leaves audio_path untouched', function (): void {
    Http::fake(['http://tts.test/tts' => Http::response('boom', 500)]);
    $word = audioWord('resilient');

    expect(fn () => (new PregenerateWordAudioJob($word->id))->handle(app(WordAudioService::class)))
        ->toThrow(RuntimeException::class);

    expect($word->fresh()?->audio_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles('audio'))->toBe([]);
});

it('rejects a non-audio or empty response instead of storing it', function (): void {
    $word = audioWord('resilient');
    $service = app(WordAudioService::class);

    Http::fake(['http://tts.test/tts' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
    expect(fn () => $service->generate($word))->toThrow(RuntimeException::class, 'tts_service_bad_audio');

    Http::fake(['http://tts.test/tts' => Http::response('', 200, ['Content-Type' => 'audio/mpeg'])]);
    expect(fn () => $service->generate($word))->toThrow(RuntimeException::class, 'tts_service_bad_audio');

    expect($word->fresh()?->audio_path)->toBeNull();
});

it('job has retry backoff and logs the final failure', function (): void {
    $job = new PregenerateWordAudioJob(42);
    expect($job->tries)->toBeGreaterThan(1)->and($job->backoff())->not->toBeEmpty();

    Log::spy();
    $job->failed(new RuntimeException('tts_service_failed'));
    Log::shouldHaveReceived('warning')->once();
});

it('job ignores a deleted or deactivated word', function (): void {
    fakeTts();
    $inactive = audioWord('resilient', attrs: ['is_active' => false]);
    $service = app(WordAudioService::class);

    (new PregenerateWordAudioJob($inactive->id))->handle($service);
    (new PregenerateWordAudioJob(999999))->handle($service);

    Http::assertNothingSent();
    expect($inactive->fresh()?->audio_path)->toBeNull();
});

it('audio:pregenerate queues jobs only for active words of the course', function (): void {
    Queue::fake();
    $course = Course::factory()->create(['slug' => 'ielts']);
    $other = Course::factory()->create();
    $a = audioWord('alpha', $course);
    $b = audioWord('beta', $course);
    audioWord('gamma', $course, ['is_active' => false]);
    audioWord('delta', $other);

    $this->artisan('audio:pregenerate', ['course' => 'ielts'])
        ->expectsOutputToContain('Queued: 2')
        ->assertSuccessful();

    Queue::assertPushed(PregenerateWordAudioJob::class, 2);
    Queue::assertPushed(PregenerateWordAudioJob::class, fn (PregenerateWordAudioJob $job): bool => in_array($job->wordId, [$a->id, $b->id], true) && ! $job->force && $job->queue === 'low');
});

it('audio:pregenerate skips words whose audio is current unless --force', function (): void {
    Queue::fake();
    $course = Course::factory()->create(['slug' => 'ielts']);
    $path = audioPathOf('alpha');
    Storage::disk('public')->put($path, 'x');
    audioWord('alpha', $course, ['audio_path' => $path]);
    audioWord('beta', $course);

    $this->artisan('audio:pregenerate', ['course' => 'ielts'])
        ->expectsOutputToContain('Queued: 1, already up to date: 1')
        ->assertSuccessful();
    Queue::assertPushed(PregenerateWordAudioJob::class, 1);

    Queue::fake();
    $this->artisan('audio:pregenerate', ['course' => 'ielts', '--force' => true])
        ->expectsOutputToContain('Queued: 2')
        ->assertSuccessful();
    Queue::assertPushed(PregenerateWordAudioJob::class, 2);
    Queue::assertPushed(PregenerateWordAudioJob::class, fn (PregenerateWordAudioJob $job): bool => $job->force);
});

it('audio:pregenerate fails for an unknown course', function (): void {
    Queue::fake();

    $this->artisan('audio:pregenerate', ['course' => 'nope'])->assertFailed();

    Queue::assertNothingPushed();
});

/** @return array<string, string> */
function audioAuthHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

it('GET /tts serves the stored file without calling the TTS service', function (): void {
    fakeTts();
    Storage::disk('public')->put(audioPathOf('resilient'), 'stored-bytes');
    $learner = Learner::factory()->create();

    $response = $this->withHeaders(audioAuthHeaders($learner))->get('/api/twa/tts?word=Resilient');

    $response->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('Cache-Control', 'max-age=2592000, public');
    expect($response->getContent())->toBe('stored-bytes');
    Http::assertNothingSent();
});

it('GET /tts serves the stored file when the default voice is passed explicitly', function (): void {
    fakeTts();
    Storage::disk('public')->put(audioPathOf('resilient'), 'stored-bytes');
    $learner = Learner::factory()->create();

    $this->withHeaders(audioAuthHeaders($learner))->get('/api/twa/tts?word=resilient&voice=en-US-AriaNeural')
        ->assertOk();

    Http::assertNothingSent();
});

it('GET /tts falls back to the service for another voice or an unknown word', function (): void {
    fakeTts();
    Storage::disk('public')->put(audioPathOf('resilient'), 'stored-bytes');
    $learner = Learner::factory()->create();

    $other = $this->withHeaders(audioAuthHeaders($learner))->get('/api/twa/tts?word=resilient&voice=en-GB-RyanNeural');
    expect($other->getContent())->toBe('mp3-bytes');

    $unknown = $this->withHeaders(audioAuthHeaders($learner))->get('/api/twa/tts?word=different');
    expect($unknown->getContent())->toBe('mp3-bytes');

    Http::assertSentCount(2);
});

it('exposes audio_url on the training card only when the word has audio', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create();
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $word = Word::factory()->for($lesson)->create(['word' => 'resilient']);
    $session = TrainingSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'started_by_user_id' => User::factory()->create()->id,
        'status' => 'open',
    ]);

    $this->withHeaders(audioAuthHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertOk()
        ->assertJsonPath('card.audio_url', null);

    $path = audioPathOf('resilient');
    $word->update(['audio_path' => $path]);

    $url = $this->withHeaders(audioAuthHeaders($learner))
        ->postJson("/api/twa/training/sessions/{$session->id}/start")
        ->assertOk()
        ->json('card.audio_url');

    expect($url)->toBe(Storage::disk('public')->url($path))->toEndWith('/storage/'.$path);
});
