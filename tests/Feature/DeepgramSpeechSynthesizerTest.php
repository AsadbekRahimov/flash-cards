<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AttentionList;
use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\ConfiguredSpeechSynthesizer;
use App\Domain\Content\Services\Tts\DeepgramSpeechSynthesizer;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\Tts\TtsRequestThrottle;
use App\Domain\Content\Services\TtsSampleRecorder;
use App\Domain\Content\Services\TtsUsageStats;
use App\Domain\Content\Services\WordAudioScheduler;
use App\Domain\Content\Services\WordAudioService;
use App\Domain\Operations\DTO\ReadinessReport;
use App\Domain\Operations\Services\ReadinessProbe;
use App\Domain\Practice\Enums\AudioStatus;
use App\Filament\Widgets\AttentionWidget;
use App\Jobs\GeneratePracticeAudioJob;
use App\Jobs\PregenerateWordAudioJob;
use App\Models\Lesson;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * docs/26 §10: the `deepgram` driver (Deepgram Aura-2) on Http::fake - no test talks to Deepgram. The key below is made up.
 */

uses(RefreshDatabase::class);

const DEEPGRAM_TEST_KEY = 'dg-test-key-7f3a9c1e5b2d4f6a8c0e';
const DEEPGRAM_TEST_VOICE = 'aura-2-thalia-en';

/** A fresh fake: Http::fake() on top of an earlier one would keep answering with the earlier stub. */
function deepgramFake(mixed $callback): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

function deepgramTts(): SpeechSynthesizer
{
    return app(SpeechSynthesizer::class);
}

function deepgramMp3(string $bytes = 'ID3-mp3-bytes'): PromiseInterface
{
    return Http::response($bytes, 200, ['Content-Type' => 'audio/mpeg', 'dg-char-count' => '5', 'dg-request-id' => 'a1b2c3d4-0000-1111-2222-333344445555']);
}

/** @param  array<string, string>  $extra */
function deepgramErr(int $status, string $code = 'SOME_CODE', array $extra = []): PromiseInterface
{
    return Http::response(['err_code' => $code, 'err_msg' => 'about hello', 'request_id' => 'a1b2c3d4-0000-1111-2222-333344445555', ...$extra], $status);
}

function deepgramError(string $text, string $voice = DEEPGRAM_TEST_VOICE): SpeechSynthesisException
{
    try {
        deepgramTts()->synthesize($text, $voice);
    } catch (SpeechSynthesisException $e) {
        return $e;
    }

    throw new RuntimeException('No SpeechSynthesisException.');
}

/** @return array{characters: int, requests: int}|null the one budget row of Deepgram */
function deepgramUsage(): ?array
{
    $row = DB::table(TtsCharacterBudget::TABLE)->where('month', TtsCharacterBudget::DEEPGRAM_PERIOD)->first();

    return $row === null ? null : ['characters' => (int) $row->characters, 'requests' => (int) $row->requests];
}

beforeEach(function (): void {
    config([
        'twa.tts.driver' => 'deepgram',
        'twa.tts.voice' => DEEPGRAM_TEST_VOICE,
        'twa.tts.audio_version' => 1,
        'twa.tts.deepgram.api_key' => DEEPGRAM_TEST_KEY,
        'twa.tts.deepgram.timeout' => 20,
        'twa.tts.deepgram.bit_rate' => 48000,
        'twa.tts.deepgram.max_chars' => 2000,
        'twa.tts.deepgram.rate_per_minute' => 150,
        'twa.tts.deepgram.char_limit' => 6_000_000,
        'twa.tts.monthly_char_limit' => 900_000,
    ]);
    Cache::forget(DeepgramSpeechSynthesizer::HALT_KEY);
    Cache::forget(GoogleSpeechSynthesizer::HALT_KEY);
    RateLimiter::clear(TtsRequestThrottle::KEY);
    RateLimiter::clear(TtsRequestThrottle::DEEPGRAM_KEY);
    Http::preventStrayRequests();
});

it('is chosen by TTS_DRIVER=deepgram, without case and blanks, and is a known driver', function (string $driver): void {
    config(['twa.tts.driver' => $driver]);

    expect(deepgramTts())->toBeInstanceOf(ConfiguredSpeechSynthesizer::class)
        ->and(deepgramTts()->resolve())->toBeInstanceOf(DeepgramSpeechSynthesizer::class)
        ->and(ConfiguredSpeechSynthesizer::known())->toBeTrue()
        ->and(app(WordAudioService::class)->enabled())->toBeTrue();
})->with(['deepgram', 'DeepGram', " deepgram\n"]);

it('posts the text to /v1/speak with the model, mp3 and the bit rate, the key as a Token, and returns the mp3', function (): void {
    $options = [];
    Http::fake(function (HttpRequest $request, array $sent) use (&$options) {
        $options = $sent;

        return deepgramMp3('ID3-the-mp3');
    });

    $audio = deepgramTts()->synthesize('ice cream', DEEPGRAM_TEST_VOICE);

    expect($audio)->toBe(['body' => 'ID3-the-mp3', 'content_type' => 'audio/mpeg'])
        ->and($options['timeout'] ?? null)->toBe(20)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and(deepgramUsage())->toBe(['characters' => 9, 'requests' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.deepgram.com/v1/speak?model=aura-2-thalia-en&encoding=mp3&bit_rate=48000')
            ->and($request->header('Authorization'))->toBe(['Token '.DEEPGRAM_TEST_KEY])
            ->and($request->header('Content-Type'))->toBe(['application/json'])
            ->and(json_decode($request->body(), true))->toBe(['text' => 'ice cream']);

        return true;
    });
});

it('takes the bit rate of the setting when Deepgram takes it, else 48000', function (mixed $setting, int $sent): void {
    config(['twa.tts.deepgram.bit_rate' => $setting]);
    Http::fake(['*' => deepgramMp3()]);

    deepgramTts()->synthesize('hello', DEEPGRAM_TEST_VOICE);

    Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), "&bit_rate={$sent}"));
})->with([
    '32000' => [32000, 32000],
    '48000' => [48000, 48000],
    'not one of mp3' => [64000, 48000],
    'zero' => [0, 48000],
]);

it('refuses a voice of Google or edge-tts with tts_voice_unsupported, before the key, the pace and the budget', function (string $voice): void {
    Http::fake();

    $e = deepgramError('hello', $voice);

    expect($e->getMessage())->toBe('tts_voice_unsupported')
        ->and($e->retryable)->toBeFalse()
        ->and(deepgramUsage())->toBeNull()
        ->and(RateLimiter::attempts(TtsRequestThrottle::DEEPGRAM_KEY))->toBe(0);
    Http::assertNothingSent();
})->with(['en-US-Neural2-F', 'en-US-Chirp3-HD-Charon', 'en-US-AriaNeural', 'Aura-2-Thalia-en', 'aura-2-thalia', '', 'aura-2-thalia-en&x=1']);

it('accepts the voices of Aura-2 and Aura', function (string $voice): void {
    Http::fake(['*' => deepgramMp3()]);

    expect(deepgramTts()->synthesize('hello', $voice)['body'])->toBe('ID3-mp3-bytes')
        ->and(DeepgramSpeechSynthesizer::supportsVoice($voice))->toBeTrue();
})->with(['aura-2-thalia-en', 'aura-2-andromeda-en', 'aura-2-arcas-en', 'aura-2-apollo-en', 'aura-2-celeste-es', 'aura-asteria-en']);

it('refuses a text longer than one request takes with tts_text_too_long, an empty one as rejected, without HTTP or budget', function (): void {
    config(['twa.tts.deepgram.max_chars' => 10]);
    Http::fake();

    $long = deepgramError('abcdefghijk');
    $empty = deepgramError('');

    expect($long->getMessage())->toBe('tts_text_too_long')
        ->and($long->retryable)->toBeFalse()
        ->and($empty->getMessage())->toBe('tts_request_rejected')
        ->and(deepgramUsage())->toBeNull();
    Http::assertNothingSent();
});

it('counts the limit of one request in characters, not bytes, and never above 2000', function (): void {
    Http::fake(['*' => deepgramMp3()]);
    config(['twa.tts.deepgram.max_chars' => 5]);
    expect(deepgramTts()->synthesize('ёжики', DEEPGRAM_TEST_VOICE)['body'])->toBe('ID3-mp3-bytes'); // 5 characters, 10 bytes

    config(['twa.tts.deepgram.max_chars' => 50_000]);
    expect(DeepgramSpeechSynthesizer::maxChars())->toBe(2000)
        ->and(deepgramError(str_repeat('a', 2001))->getMessage())->toBe('tts_text_too_long');
    expect(deepgramTts()->synthesize(str_repeat('a', 2000), DEEPGRAM_TEST_VOICE)['body'])->toBe('ID3-mp3-bytes');
});

it('without DEEPGRAM_API_KEY it is tts_service_not_configured, final, nothing sent or reserved', function (string $key): void {
    config(['twa.tts.deepgram.api_key' => $key]);
    Http::fake();

    $e = deepgramError('hello');

    expect($e->getMessage())->toBe('tts_service_not_configured')
        ->and($e->retryable)->toBeFalse()
        ->and(deepgramUsage())->toBeNull();
    Http::assertNothingSent();
})->with(['empty' => [''], 'blanks' => ['   ']]);

it('classifies the answers of Deepgram and gives the characters back for every refusal', function (int $status, string $code, bool $retryable, bool $halts): void {
    Http::fake(['*' => deepgramErr($status)]);

    $e = deepgramError('hello');

    expect($e->getMessage())->toBe($code)
        ->and($e->retryable)->toBe($retryable)
        ->and($e->getPrevious())->toBeNull()
        ->and(deepgramUsage())->toBe(['characters' => 0, 'requests' => 0])
        ->and(Cache::get(DeepgramSpeechSynthesizer::HALT_KEY))->toBe($halts ? ['code' => $code] : null);
    Http::assertSentCount(1);
})->with([
    '400' => [400, 'tts_request_rejected', false, false],
    '404' => [404, 'tts_request_rejected', false, false],
    '422 (other 4xx)' => [422, 'tts_request_rejected', false, false],
    '401 (wrong key)' => [401, 'tts_auth_failed', false, true],
    '402 (no money)' => [402, 'tts_payment_required', false, true],
    '403 (no permission)' => [403, 'tts_forbidden', false, true],
    '408' => [408, 'tts_rate_limited', true, false],
    '429' => [429, 'tts_rate_limited', true, false],
    '500' => [500, 'tts_service_failed', true, false],
    '503' => [503, 'tts_service_failed', true, false],
]);

it('after 401 / 402 / 403 halts: the next call sends nothing and fails with the same code until --clear-halt', function (int $status, string $code): void {
    Http::fake(['*' => deepgramErr($status)]);
    deepgramError('hello');

    deepgramFake(['*' => deepgramMp3()]);
    $again = deepgramError('world');

    expect($again->getMessage())->toBe($code)
        ->and(DeepgramSpeechSynthesizer::haltCode())->toBe($code)
        ->and(ConfiguredSpeechSynthesizer::haltCode())->toBe($code)
        ->and(deepgramUsage())->toBe(['characters' => 0, 'requests' => 0]);
    Http::assertNothingSent();

    expect(DeepgramSpeechSynthesizer::clearHalt())->toBeTrue()
        ->and(deepgramTts()->synthesize('world', DEEPGRAM_TEST_VOICE)['body'])->toBe('ID3-mp3-bytes');
})->with([[401, 'tts_auth_failed'], [402, 'tts_payment_required'], [403, 'tts_forbidden']]);

it('ignores a halt marker that is not the array it writes, and the marker of Google', function (): void {
    Cache::put(DeepgramSpeechSynthesizer::HALT_KEY, 'tts_auth_failed', 600);
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, ['code' => 'tts_auth_failed'], 600);
    Http::fake(['*' => deepgramMp3()]);

    expect(DeepgramSpeechSynthesizer::haltCode())->toBeNull()
        ->and(ConfiguredSpeechSynthesizer::haltCode())->toBeNull()
        ->and(deepgramTts()->synthesize('hello', DEEPGRAM_TEST_VOICE)['body'])->toBe('ID3-mp3-bytes');
});

it('a dropped connection or a timeout is the retryable tts_service_failed and keeps the characters', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds')]);

    $e = deepgramError('hello');

    expect($e->getMessage())->toBe('tts_service_failed')
        ->and($e->retryable)->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and($e->getPrevious()?->getMessage())->toContain('Operation timed out')
        // the cause is a copy without its chain: the Guzzle exception under it holds the request and its header
        ->and($e->getPrevious()?->getPrevious())->toBeNull()
        ->and(deepgramUsage())->toBe(['characters' => 5, 'requests' => 1]);
});

it('a 200 without usable audio is the retryable tts_service_bad_audio and keeps the characters', function (string $body, string $type): void {
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => $type])]);

    $e = deepgramError('hello');

    expect($e->getMessage())->toBe('tts_service_bad_audio')
        ->and($e->retryable)->toBeTrue()
        ->and(deepgramUsage())->toBe(['characters' => 5, 'requests' => 1]);
})->with([
    'empty' => ['', 'audio/mpeg'],
    'json' => ['{"err_code":"X"}', 'application/json'],
    'a page' => ['<html>proxy</html>', 'text/html; charset=utf-8'],
]);

it('logs only the HTTP code, the err_code enum, the request id and the length of a refused request', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });
    Http::fake(['*' => Http::response(['err_code' => 'INVALID_QUERY_PARAMETER', 'err_msg' => 'bad text: confidential sentence', 'request_id' => 'a1b2c3d4-0000-1111-2222-333344445555'], 400)]);

    deepgramError('confidential sentence');

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->message)->toBe('tts.deepgram_refused')
        ->and($logged[0]->context)->toBe(['status' => 400, 'err_code' => 'INVALID_QUERY_PARAMETER', 'request_id' => 'a1b2c3d4-0000-1111-2222-333344445555', 'chars' => 21]);

    $logged = [];
    deepgramFake(['*' => Http::response(['err_code' => 'free text with the key '.DEEPGRAM_TEST_KEY, 'request_id' => 'no such id!'], 400)]);
    deepgramError('hello');
    expect($logged[0]->context)->toBe(['status' => 400, 'err_code' => null, 'request_id' => null, 'chars' => 5]);
});

it('never lets the key into an exception message, its previous, a log line, a trace or audio_error', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $answers = [
        fn () => deepgramErr(401, 'INVALID_AUTH'),
        fn () => deepgramErr(402, 'ASR_PAYMENT_REQUIRED'),
        fn () => deepgramErr(403, 'INSUFFICIENT_PERMISSIONS'),
        fn () => deepgramErr(429, 'TOO_MANY_REQUESTS'),
        fn () => deepgramErr(500, 'INTERNAL'),
        fn () => deepgramErr(400, 'BAD_REQUEST'),
        // A transport error that quotes the key (a proxy might): the copy hides it.
        fn () => throw new ConnectionException('cURL error 7: Failed to connect, header Token '.DEEPGRAM_TEST_KEY),
        fn () => Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
    ];
    $exceptions = [];
    foreach ($answers as $answer) {
        Cache::forget(DeepgramSpeechSynthesizer::HALT_KEY);
        deepgramFake(fn () => $answer());
        $exceptions[] = deepgramError('hello');
    }

    // A dictation that fails through the job: audio_error and the job log.
    Storage::fake('practice_audio');
    config(['queue.default' => 'sync']);
    Cache::forget(DeepgramSpeechSynthesizer::HALT_KEY);
    deepgramFake(['*' => deepgramErr(401, 'INVALID_AUTH')]);
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    GeneratePracticeAudioJob::dispatch($version->id);
    $failed = $version->fresh();

    expect($failed?->audio_status)->toBe(AudioStatus::Failed)
        ->and($failed?->audio_error)->toBe('tts_auth_failed');

    $texts = [(string) $failed?->audio_error, ...$logged];
    foreach ($exceptions as $e) {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $texts[] = $cause->getMessage();
            $texts[] = $cause->getTraceAsString();
        }
    }

    expect(implode("\n", $texts))->not->toContain(DEEPGRAM_TEST_KEY)->not->toContain(substr(DEEPGRAM_TEST_KEY, 8, 16));
});

it('waits for its own pace limit before reserving and calling: tts_rate_limited after 30 seconds', function (): void {
    config(['twa.tts.deepgram.rate_per_minute' => 1, 'twa.tts.rate_per_minute' => 1000]);
    Sleep::fake();
    RateLimiter::hit(TtsRequestThrottle::DEEPGRAM_KEY, 60);
    Http::fake();

    $e = deepgramError('hello');

    expect($e->getMessage())->toBe('tts_rate_limited')
        ->and($e->retryable)->toBeTrue()
        ->and(deepgramUsage())->toBeNull()
        // the limiter of Google is not touched
        ->and(RateLimiter::attempts(TtsRequestThrottle::KEY))->toBe(0);
    Http::assertNothingSent();
    Sleep::assertSleptTimes(6);
});

it('counts the whole credit in one row that never starts over: one character over is tts_credit_limit_reached without HTTP', function (): void {
    config(['twa.tts.deepgram.char_limit' => 10]);
    Http::fake(['*' => deepgramMp3()]);

    deepgramTts()->synthesize('hello', DEEPGRAM_TEST_VOICE);
    $this->travel(2)->months(); // no new month for Deepgram
    deepgramTts()->synthesize('world', DEEPGRAM_TEST_VOICE); // exactly the limit
    $e = deepgramError('a');

    expect($e->getMessage())->toBe('tts_credit_limit_reached')
        ->and($e->retryable)->toBeFalse()
        ->and($e->isBudgetSpent())->toBeTrue()
        ->and(deepgramUsage())->toBe(['characters' => 10, 'requests' => 2])
        ->and(DB::table(TtsCharacterBudget::TABLE)->count())->toBe(1)
        ->and(app(TtsCharacterBudget::class)->exhausted())->toBeTrue()
        ->and(app(TtsCharacterBudget::class)->usage())->toBe(['month' => 'dgtotal', 'characters' => 10, 'limit' => 10]);
    Http::assertSentCount(2);
});

it('keeps the budgets of Google and Deepgram apart', function (): void {
    config(['twa.tts.deepgram.char_limit' => 100, 'twa.tts.monthly_char_limit' => 5]);
    $budget = app(TtsCharacterBudget::class);

    $budget->reserve(5, ConfiguredSpeechSynthesizer::GOOGLE);
    $period = $budget->reserve(50, ConfiguredSpeechSynthesizer::DEEPGRAM);

    expect($period)->toBe('dgtotal')
        ->and(fn () => $budget->reserve(1, ConfiguredSpeechSynthesizer::GOOGLE))->toThrow(SpeechSynthesisException::class, 'tts_monthly_limit_reached')
        ->and($budget->usage(ConfiguredSpeechSynthesizer::GOOGLE)['characters'])->toBe(5)
        ->and($budget->usage(ConfiguredSpeechSynthesizer::DEEPGRAM))->toBe(['month' => 'dgtotal', 'characters' => 50, 'limit' => 100])
        ->and(fn () => $budget->reserve(0, ConfiguredSpeechSynthesizer::DEEPGRAM))->not->toThrow(SpeechSynthesisException::class);

    config(['twa.tts.deepgram.char_limit' => 0]);
    expect(fn () => $budget->reserve(1, ConfiguredSpeechSynthesizer::DEEPGRAM))->toThrow(SpeechSynthesisException::class, 'tts_credit_limit_reached');
});

it('the word job ends quietly when the credit limit is spent (no failed job), like the monthly limit', function (): void {
    config(['twa.tts.deepgram.char_limit' => 1]);
    Storage::fake('public');
    Http::fake();
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create(['word' => 'resilient']);
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldNotReceive('fail');
    $job = new PregenerateWordAudioJob($word->id);
    $job->setJob($queueJob);

    $job->handle(app(WordAudioService::class));

    expect($word->fresh()?->audio_path)->toBeNull();
    Http::assertNothingSent();
});

it('defaults the voice to aura-2-thalia-en when TTS_DRIVER=deepgram and TTS_VOICE is empty', function (array $env, string $voice): void {
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $config = require config_path('twa.php');
    } finally {
        foreach ($saved as $key => [$env, $envArray, $server]) {
            putenv($env === false ? $key : "{$key}={$env}");
            if ($envArray === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envArray;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }

    expect($config['tts']['voice'])->toBe($voice);
})->with([
    'deepgram, no voice' => [['TTS_DRIVER' => 'deepgram', 'TTS_VOICE' => ''], 'aura-2-thalia-en'],
    'Deepgram with blanks, no voice' => [['TTS_DRIVER' => ' Deepgram ', 'TTS_VOICE' => ''], 'aura-2-thalia-en'],
    'deepgram, a voice' => [['TTS_DRIVER' => 'deepgram', 'TTS_VOICE' => 'aura-2-apollo-en'], 'aura-2-apollo-en'],
    'google, no voice' => [['TTS_DRIVER' => 'google', 'TTS_VOICE' => ''], 'en-US-AriaNeural'],
    'no driver, no voice' => [['TTS_DRIVER' => '', 'TTS_VOICE' => ''], 'en-US-AriaNeural'],
]);

it('reports deepgram without a key as degraded in /health/ready, and nothing once the key is set', function (): void {
    config(['twa.tts.deepgram.api_key' => '']);
    expect(app(ReadinessProbe::class)->run()->checks)->toHaveKey('tts', ReadinessReport::DEGRADED)
        ->and(ConfiguredSpeechSynthesizer::misconfigured())->toBeTrue();

    config(['twa.tts.deepgram.api_key' => DEEPGRAM_TEST_KEY]);
    expect(app(ReadinessProbe::class)->run()->checks)->not->toHaveKey('tts')
        ->and(ConfiguredSpeechSynthesizer::misconfigured())->toBeFalse();
});

it('voices examples up to the characters of one Deepgram request only', function (): void {
    config(['twa.tts.deepgram.max_chars' => 20]);
    $audio = app(WordAudioService::class);

    expect($audio->exampleText('I read this book.'))->toBe('I read this book.')
        ->and($audio->exampleText('I read this book last year.'))->toBeNull();

    config(['twa.tts.driver' => 'google']);
    expect($audio->exampleText('I read this book last year.'))->toBe('I read this book last year.');
});

it('reconcile queues nothing for deepgram while it is halted, has no key, the voice is not its own, or the credit is spent', function (Closure $break, string $message): void {
    Queue::fake();
    Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create(['word' => 'apple', 'is_active' => true]);
    $break();

    $this->artisan('audio:reconcile')->expectsOutputToContain($message)->assertSuccessful();
    Queue::assertNothingPushed();
})->with([
    'halted' => [fn () => Cache::put(DeepgramSpeechSynthesizer::HALT_KEY, ['code' => 'tts_payment_required'], 600), 'Deepgram refused the key or the payment'],
    'no key' => [fn () => config(['twa.tts.deepgram.api_key' => '']), 'DEEPGRAM_API_KEY is empty'],
    'a voice of Google' => [fn () => config(['twa.tts.voice' => 'en-US-Neural2-F']), 'TTS_VOICE is not a voice of Deepgram'],
    'credit spent' => [function (): void {
        config(['twa.tts.deepgram.char_limit' => 10]);
        DB::table(TtsCharacterBudget::TABLE)->insert(['month' => 'dgtotal', 'characters' => 10, 'requests' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }, 'The Deepgram character limit'],
]);

it('reconcile queues the words with deepgram configured', function (): void {
    Queue::fake();
    Word::factory()->for(Lesson::factory()->for(Stage::factory()))->create(['word' => 'apple', 'is_active' => true]);

    expect(app(WordAudioScheduler::class)->reconcile()['reason'])->toBeNull();
});

it('shows the credit and the halt of Deepgram in «Требуют внимания» of /admin', function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    config(['learning.reminders.quiz.experiment.enabled' => false, 'twa.tts.deepgram.char_limit' => 1_000_000]);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    DB::table(TtsCharacterBudget::TABLE)->insert(['month' => 'dgtotal', 'characters' => 950_000, 'requests' => 9, 'created_at' => now(), 'updated_at' => now()]);
    // A full month of Google does not count for Deepgram.
    DB::table(TtsCharacterBudget::TABLE)->insert(['month' => app(TtsCharacterBudget::class)->month(), 'characters' => 900_000, 'requests' => 9, 'created_at' => now(), 'updated_at' => now()]);
    Cache::put(DeepgramSpeechSynthesizer::HALT_KEY, ['code' => 'tts_payment_required'], 600);

    $items = app(AttentionList::class)->items();

    expect(app(TtsUsageStats::class)->current())->toMatchArray(['driver' => 'deepgram', 'month' => 'dgtotal', 'characters' => 950_000, 'near' => true, 'exhausted' => false])
        ->and($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['kind' => AttentionList::KIND_TTS_HALTED, 'level' => 'danger', 'name' => 'deepgram', 'state' => 'tts_payment_required'])
        ->and($items[1])->toMatchArray(['kind' => AttentionList::KIND_TTS_BUDGET, 'state' => 'deepgram', 'count' => 950_000, 'total' => 1_000_000, 'value' => 95.0]);

    Livewire::test(AttentionWidget::class)
        ->assertSee('Deepgram · озвучка остановлена')
        ->assertSee('Deepgram отклонил запрос (tts_payment_required)')
        ->assertSee('Deepgram · расход кредита')
        ->assertSee("Израсходовано 95\u{00A0}% предела кредита: 950\u{00A0}000 из 1\u{00A0}000\u{00A0}000 символов")
        ->assertSee('весь кредит')
        ->assertDontSee('Google');
});

it('audio:sample records several voices in one run, one folder each, and prints the credit used', function (): void {
    $dir = sys_get_temp_dir().'/audio-sample-dg-'.Str::random(8);
    Http::fake(['*' => deepgramMp3()]);

    try {
        $this->artisan('audio:sample', ['--voices' => 'aura-2-thalia-en, aura-2-andromeda-en,aura-2-thalia-en', '--dir' => $dir])
            ->expectsOutputToContain('Voice aura-2-thalia-en, driver deepgram, into '.$dir.'/aura-2-thalia-en')
            ->expectsOutputToContain('Voice aura-2-andromeda-en, driver deepgram, into '.$dir.'/aura-2-andromeda-en')
            ->expectsOutputToContain('Deepgram characters used:')
            ->assertSuccessful();

        $total = count(app(TtsSampleRecorder::class)->samples());
        expect(File::files($dir.'/aura-2-thalia-en'))->toHaveCount($total)
            ->and(File::files($dir.'/aura-2-andromeda-en'))->toHaveCount($total);
        Http::assertSentCount(2 * $total);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'model=aura-2-andromeda-en'));
    } finally {
        File::deleteDirectory($dir);
    }
});

it('audio:sample goes on to the next voice after tts_voice_unsupported, and stops all voices at a refused key', function (): void {
    $dir = sys_get_temp_dir().'/audio-sample-dg-'.Str::random(8);
    Http::fake(['*' => deepgramMp3()]);

    try {
        $this->artisan('audio:sample', ['--voices' => 'en-US-Neural2-F,aura-2-apollo-en', '--dir' => $dir])
            ->expectsOutputToContain('This is not a voice of Deepgram (tts_voice_unsupported)')
            ->expectsOutputToContain('Stopped after a final error: tts_voice_unsupported')
            ->expectsOutputToContain('Voice aura-2-apollo-en')
            ->assertFailed();
        expect(File::files($dir.'/aura-2-apollo-en'))->not->toBeEmpty();

        deepgramFake(['*' => deepgramErr(401, 'INVALID_AUTH')]);
        $this->artisan('audio:sample', ['--voices' => 'aura-2-thalia-en,aura-2-apollo-en', '--dir' => $dir.'/b'])
            ->expectsOutputToContain('Stopped after a final error: tts_auth_failed')
            ->doesntExpectOutputToContain('Voice aura-2-apollo-en')
            ->assertFailed();
        Http::assertSentCount(1);

        // The halt is announced, and --clear-halt drops it.
        $this->artisan('audio:sample', ['--voice' => 'aura-2-thalia-en', '--dir' => $dir.'/c'])
            ->expectsOutputToContain('Deepgram refused the key or the payment a short while ago (tts_auth_failed)')
            ->assertFailed();
        deepgramFake(['*' => deepgramMp3()]);
        $this->artisan('audio:sample', ['--voice' => 'aura-2-thalia-en', '--dir' => $dir.'/d', '--clear-halt' => true])
            ->expectsOutputToContain('The halt marker of the deepgram driver is cleared')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($dir);
    }
});

it('audio:sample refuses a bad list of voices before any call', function (string $voices): void {
    Http::fake();

    $this->artisan('audio:sample', ['--voices' => $voices, '--dir' => sys_get_temp_dir().'/never'])->assertExitCode(2);

    Http::assertNothingSent();
})->with([
    'a path' => ['aura-2-thalia-en,../etc'],
    'only commas' => [' , ,'],
    'too many' => [implode(',', array_map(fn (int $i): string => "aura-2-v{$i}x-en", range(1, 11)))],
]);

it('audio:sample warns when DEEPGRAM_API_KEY is empty', function (): void {
    config(['twa.tts.deepgram.api_key' => '']);
    Http::fake();

    $this->artisan('audio:sample', ['--dir' => sys_get_temp_dir().'/audio-sample-dg-'.Str::random(8)])
        ->expectsOutputToContain('TTS_DRIVER=deepgram, but DEEPGRAM_API_KEY is empty')
        ->expectsOutputToContain('Stopped after a final error: tts_service_not_configured')
        ->assertFailed();
    Http::assertNothingSent();
});
