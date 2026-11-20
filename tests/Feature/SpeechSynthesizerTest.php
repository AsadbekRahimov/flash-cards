<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\HttpSpeechSynthesizer;
use App\Domain\Content\Services\Tts\NullSpeechSynthesizer;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Operations\DTO\ReadinessReport;
use App\Domain\Operations\Services\ReadinessProbe;
use App\Domain\Practice\Enums\AudioStatus;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Jobs\GeneratePracticeAudioJob;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Lesson;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    ]);
    Http::preventStrayRequests();
});

/** A synthesizer that answers every text with the same mp3 and remembers what it was asked. */
function synthFake(): SpeechSynthesizer
{
    return new class implements SpeechSynthesizer
    {
        /** @var list<array{string, string}> */
        public array $calls = [];

        public function synthesize(string $text, string $voice): array
        {
            $this->calls[] = [$text, $voice];

            return ['body' => 'fake-mp3', 'content_type' => 'audio/mpeg'];
        }
    };
}

it('chooses the driver from twa.tts.driver at the synthesis, without case and blanks', function (?string $driver, string $url, string $class): void {
    config(['twa.tts.driver' => $driver, 'twa.tts.service_url' => $url]);

    $tts = app(SpeechSynthesizer::class);

    expect($tts)->toBeInstanceOf(ConfiguredSpeechSynthesizer::class)
        ->and($tts->resolve())->toBeInstanceOf($class);
})->with([
    'google' => ['google', 'http://tts.test', GoogleSpeechSynthesizer::class],
    'Google with blanks' => [' Google ', 'http://tts.test', GoogleSpeechSynthesizer::class],
    'http' => ['http', '', HttpSpeechSynthesizer::class],
    'HTTP' => ['HTTP', '', HttpSpeechSynthesizer::class],
    'null, even with a service url' => ['null', 'http://tts.test', NullSpeechSynthesizer::class],
    'NULL with a newline' => ["NULL\n", 'http://tts.test', NullSpeechSynthesizer::class],
    'unset with a service url: http' => [null, 'http://tts.test', HttpSpeechSynthesizer::class],
    'empty with a service url: http' => ['', 'http://tts.test', HttpSpeechSynthesizer::class],
    'blank with a service url: http' => ['  ', 'http://tts.test', HttpSpeechSynthesizer::class],
    'unset without a service url: null' => [null, '', NullSpeechSynthesizer::class],
]);

it('follows a config change after the synthesizer was built', function (): void {
    config(['twa.tts.driver' => 'null']);
    $tts = app(SpeechSynthesizer::class);

    config(['twa.tts.driver' => 'google']);

    expect($tts->resolve())->toBeInstanceOf(GoogleSpeechSynthesizer::class);
});

it('an unknown driver breaks no service: the services are built, only the synthesis fails with the final not_configured', function (): void {
    config(['twa.tts.driver' => 'gogle']);
    Log::spy();

    // The services of the screens of a learner take the synthesizer in their constructors.
    $words = app(WordAudioService::class);
    app(PracticeAudioService::class);
    expect($words->pathFor('resilient'))->not->toBeNull();

    try {
        app(SpeechSynthesizer::class)->synthesize('resilient', 'en-US-AriaNeural');
        $this->fail('No SpeechSynthesisException.');
    } catch (SpeechSynthesisException $e) {
        expect($e->getMessage())->toBe(SpeechSynthesisException::NOT_CONFIGURED)
            ->and($e->retryable)->toBeFalse();
    }

    Log::shouldHaveReceived('warning')->with('tts.driver_unknown', Mockery::on(fn (array $context): bool => $context['driver'] === 'gogle'));
    expect(ConfiguredSpeechSynthesizer::known())->toBeFalse();
    Http::assertNothingSent();
});

it('the word job of an unknown driver fails at once with tts_service_not_configured', function (): void {
    config(['twa.tts.driver' => 'polly']);
    Storage::fake('public');
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create(['word' => 'resilient']);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::on(fn ($e): bool => $e instanceof SpeechSynthesisException && $e->getMessage() === SpeechSynthesisException::NOT_CONFIGURED));
    $job = new PregenerateWordAudioJob($word->id);
    $job->setJob($queueJob);

    $job->handle(app(WordAudioService::class));

    expect($word->fresh()?->audio_path)->toBeNull();
});

it('reports an unknown driver as degraded in /health/ready, and nothing while it is known', function (): void {
    config(['twa.tts.driver' => 'polly']);
    expect(app(ReadinessProbe::class)->run()->checks)->toHaveKey('tts', ReadinessReport::DEGRADED);

    config(['twa.tts.driver' => 'google']);
    expect(app(ReadinessProbe::class)->run()->checks)->not->toHaveKey('tts');
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
        ->and($fake->calls)->toBe([['resilient', 'en-US-AriaNeural']])
        ->and(Storage::disk('public')->get((string) $word->audio_path))->toBe('fake-mp3');
});

it('the dictation audio voices through the bound synthesizer', function (): void {
    Storage::fake('practice_audio');
    $fake = synthFake();
    app()->instance(SpeechSynthesizer::class, $fake);
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    $version->forceFill(['content' => [...$version->content, 'text' => 'See you tomorrow', 'accepted' => ['See you tomorrow']]])->save();

    app(PracticeAudioService::class)->generate($version->fresh() ?? $version);

    expect($fake->calls)->toBe([['See you tomorrow', 'en-US-AriaNeural']])
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
