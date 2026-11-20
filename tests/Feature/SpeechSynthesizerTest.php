<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\HttpSpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Twa\Services\TtsFallbackService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\Lesson;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * docs/26 §5.1: the speech synthesizer behind an interface. The driver is `twa.tts.driver` (TTS_DRIVER); without it an
 * installation keeps its old behaviour (`http` with a TTS_SERVICE_URL, else `null`). No test talks to a real service.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'twa.tts.driver' => null,
        'twa.tts.service_url' => 'http://tts.test',
        'twa.tts.voice' => 'en-US-AriaNeural',
        'twa.tts.audio_version' => 1,
        'twa.tts.timeout' => 8,
        'twa.tts.live_timeout' => 3,
        'twa.tts.live_connect_timeout' => 2,
    ]);
    Http::preventStrayRequests();
});

/** A synthesizer that answers every text with the same mp3 and remembers what it was asked. */
function synthFake(): SpeechSynthesizer
{
    return new class implements SpeechSynthesizer
    {
        /** @var list<array{string, string, bool}> */
        public array $calls = [];

        public function synthesize(string $text, string $voice, bool $live = false): array
        {
            $this->calls[] = [$text, $voice, $live];

            return ['body' => 'fake-mp3', 'content_type' => 'audio/mpeg'];
        }
    };
}

it('chooses the driver from twa.tts.driver', function (?string $driver, string $url, string $class): void {
    config(['twa.tts.driver' => $driver, 'twa.tts.service_url' => $url]);

    expect(app(SpeechSynthesizer::class))->toBeInstanceOf($class);
})->with([
    'http' => ['http', '', HttpSpeechSynthesizer::class],
    'null, even with a service url' => ['null', 'http://tts.test', NullSpeechSynthesizer::class],
    'unset with a service url: http' => [null, 'http://tts.test', HttpSpeechSynthesizer::class],
    'empty with a service url: http' => ['', 'http://tts.test', HttpSpeechSynthesizer::class],
    'unset without a service url: null' => [null, '', NullSpeechSynthesizer::class],
]);

it('refuses an unknown driver with a clear error when the synthesizer is resolved', function (): void {
    config(['twa.tts.driver' => 'polly']);

    expect(fn () => app(SpeechSynthesizer::class))->toThrow(InvalidArgumentException::class, "Unknown TTS driver 'polly'");
});

it('null driver: the final tts_service_not_configured, nothing is sent', function (): void {
    Http::fake();

    try {
        (new NullSpeechSynthesizer)->synthesize('resilient', 'en-US-AriaNeural');
        $this->fail('No exception.');
    } catch (SpeechSynthesisException $e) {
        expect($e->getMessage())->toBe(SpeechSynthesisException::NOT_CONFIGURED)
            ->and($e->getMessage())->toBe('tts_service_not_configured')
            ->and($e->retryable)->toBeFalse();
    }

    Http::assertNothingSent();
});

it('http driver: posts the text and the voice with the long timeout and returns the mp3', function (): void {
    $seen = [];
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']);
    });

    $audio = (new HttpSpeechSynthesizer)->synthesize('resilient', 'en-GB-SoniaNeural');

    expect($audio)->toBe(['body' => 'mp3-bytes', 'content_type' => 'audio/mpeg'])
        ->and($seen['timeout'] ?? null)->toBe(8);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://tts.test/tts'
        && $request['word'] === 'resilient'
        && $request['voice'] === 'en-GB-SoniaNeural'
        && $request->hasHeader('Accept', 'audio/mpeg'));
});

it('http driver: a live synthesis uses the short timeouts', function (): void {
    $seen = [];
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']);
    });

    (new HttpSpeechSynthesizer)->synthesize('resilient', 'en-US-AriaNeural', live: true);

    expect($seen['timeout'] ?? null)->toBe(3)->and($seen['connect_timeout'] ?? null)->toBe(2);
});

it('http driver: a reply without a content type is audio/mpeg; an empty voice is not sent', function (): void {
    Http::fake(['http://tts.test/tts' => Http::response('mp3-bytes', 200)]);

    $audio = (new HttpSpeechSynthesizer)->synthesize('resilient', '');

    expect($audio['content_type'])->toBe('audio/mpeg');
    Http::assertSent(fn ($request): bool => ! array_key_exists('voice', $request->data()));
});

it('http driver: without a service url it is tts_service_not_configured, final, nothing is sent', function (): void {
    config(['twa.tts.service_url' => '']);
    Http::fake();

    expect(fn () => (new HttpSpeechSynthesizer)->synthesize('resilient', 'en-US-AriaNeural'))
        ->toThrow(fn (SpeechSynthesisException $e) => expect($e->getMessage())->toBe('tts_service_not_configured')
            ->and($e->retryable)->toBeFalse());
    Http::assertNothingSent();
});

it('http driver: a refusal of the service is the retryable tts_service_failed', function (): void {
    Http::fake(['http://tts.test/tts' => Http::response('boom', 500)]);

    expect(fn () => (new HttpSpeechSynthesizer)->synthesize('resilient', 'en-US-AriaNeural'))
        ->toThrow(fn (SpeechSynthesisException $e) => expect($e->getMessage())->toBe('tts_service_failed')
            ->and($e->retryable)->toBeTrue());
});

it('http driver: an unreachable service is the retryable tts_service_failed, the cause kept, no url in the message', function (): void {
    Http::fake(['http://tts.test/tts' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to http://tts.test/tts')]);

    expect(fn () => (new HttpSpeechSynthesizer)->synthesize('resilient', 'en-US-AriaNeural'))
        ->toThrow(fn (SpeechSynthesisException $e) => expect($e->getMessage())->toBe('tts_service_failed')
            ->and($e->retryable)->toBeTrue()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class));
});

it('the word audio voices through the bound synthesizer', function (): void {
    Storage::fake('public');
    $fake = synthFake();
    app()->instance(SpeechSynthesizer::class, $fake);
    $lesson = Lesson::factory()->for(Stage::factory())->create();
    $word = Word::factory()->for($lesson)->create(['word' => 'Resilient']);

    expect(app(WordAudioService::class)->generate($word))->toBeTrue()
        ->and($fake->calls)->toBe([['resilient', 'en-US-AriaNeural', false]])
        ->and(Storage::disk('public')->get((string) $word->audio_path))->toBe('fake-mp3');
});

it('the dictation audio voices through the bound synthesizer', function (): void {
    Storage::fake('practice_audio');
    $fake = synthFake();
    app()->instance(SpeechSynthesizer::class, $fake);
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    $version->forceFill(['content' => [...$version->content, 'text' => 'See you tomorrow', 'accepted' => ['See you tomorrow']]])->save();

    app(PracticeAudioService::class)->generate($version->fresh() ?? $version);

    expect($fake->calls)->toBe([['See you tomorrow', 'en-US-AriaNeural', false]])
        ->and($version->fresh()?->audio_status)->toBe(AudioStatus::Ready);
});

it('TTS_DRIVER=null fails the dictation job at once with tts_service_not_configured, even with a service url', function (): void {
    config(['twa.tts.driver' => 'null']);
    Storage::fake('practice_audio');
    Http::fake();
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(fn (mixed $e): bool => $e instanceof SpeechSynthesisException && $e->getMessage() === 'tts_service_not_configured'));
    $queueJob->shouldNotReceive('release');
    $job = new GeneratePracticeAudioJob($version->id);
    $job->setJob($queueJob);

    $job->handle(app(PracticeAudioService::class));

    Http::assertNothingSent();
});

it('the live fallback of GET /api/twa/tts without a voice asks the synthesizer for the configured one, live', function (): void {
    $fake = synthFake();
    app()->instance(SpeechSynthesizer::class, $fake);

    $audio = app(TtsFallbackService::class)->synthesizeLive('resilient');

    expect($audio['body'])->toBe('fake-mp3')
        ->and($fake->calls)->toBe([['resilient', 'en-US-AriaNeural', true]]);
});
