<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\GoogleServiceAccount;
use App\Domain\Content\Services\Tts\GoogleSpeechSynthesizer;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\Tts\TtsRequestThrottle;
use App\Domain\Practice\Enums\AudioStatus;
use App\Jobs\GeneratePracticeAudioJob;
use App\Models\PracticeItemVersion;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * docs/26 §9.1-9.3, §9.7: the `google` driver on Http::fake - no test talks to Google. The RSA key pair is generated here
 * (openssl_pkey_new); no key is kept in the repository.
 */

uses(RefreshDatabase::class);

const GOOGLE_TTS_EMAIL = 'tts-bot@lexiflow-test.iam.gserviceaccount.com';
const GOOGLE_TTS_KID = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
const GOOGLE_TTS_VOICE = 'en-US-Chirp3-HD-Charon';

/** @return array{private: string, public: string} one RSA-2048 pair per test process (generating one takes a while) */
function googleTtsKeyPair(): array
{
    static $pair = null;
    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        expect($key)->not->toBeFalse();
        openssl_pkey_export($key, $private);
        $pair = ['private' => (string) $private, 'public' => (string) openssl_pkey_get_details($key)['key']];
    }

    return $pair;
}

/** A directory of this process for the key files of the tests, emptied after each test. */
function googleTtsKeyDir(): string
{
    $dir = sys_get_temp_dir().'/google-tts-test-'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0700);
    }

    return $dir;
}

/** Writes a key file (by default a valid service-account key) and points GOOGLE_TTS_CREDENTIALS at it. */
function googleTtsKeyFile(?string $contents = null, array $override = []): string
{
    $contents ??= json_encode([
        'type' => 'service_account',
        'project_id' => 'lexiflow-test',
        'private_key_id' => GOOGLE_TTS_KID,
        'private_key' => googleTtsKeyPair()['private'],
        'client_email' => GOOGLE_TTS_EMAIL,
        'client_id' => '1234567890',
        ...$override,
    ], JSON_THROW_ON_ERROR);

    $path = (string) tempnam(googleTtsKeyDir(), 'key-');
    file_put_contents($path, $contents);
    config(['twa.tts.google.credentials' => $path]);

    return $path;
}

/** A fresh fake: Http::fake() on top of an earlier one would keep answering with the earlier stub. */
function googleTtsFake(mixed $callback): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

function googleTts(): SpeechSynthesizer
{
    return app(SpeechSynthesizer::class);
}

function googleTtsOk(string $bytes = 'ID3-mp3-bytes'): array
{
    return ['audioContent' => base64_encode($bytes)];
}

function googleTtsError(string $text, string $voice = GOOGLE_TTS_VOICE): SpeechSynthesisException
{
    try {
        googleTts()->synthesize($text, $voice);
    } catch (SpeechSynthesisException $e) {
        return $e;
    }

    throw new RuntimeException('No SpeechSynthesisException.');
}

/** @return array{characters: int, requests: int}|null the budget row of the current billing month */
function googleTtsUsage(): ?array
{
    $row = DB::table('tts_usage_months')->where('month', app(TtsCharacterBudget::class)->month())->first();

    return $row === null ? null : ['characters' => (int) $row->characters, 'requests' => (int) $row->requests];
}

function googleTtsBearer(HttpRequest $request): string
{
    return substr((string) $request->header('Authorization')[0], strlen('Bearer '));
}

beforeEach(function (): void {
    config([
        'twa.tts.driver' => 'google',
        'twa.tts.voice' => GOOGLE_TTS_VOICE,
        'twa.tts.audio_version' => 1,
        'twa.tts.google.credentials' => '',
        'twa.tts.google.timeout' => 20,
        'twa.tts.rate_per_minute' => 150,
        'twa.tts.monthly_char_limit' => 900_000,
    ]);
    app()->forgetInstance(GoogleServiceAccount::class);
    Cache::forget(GoogleSpeechSynthesizer::HALT_KEY);
    RateLimiter::clear(TtsRequestThrottle::KEY);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    foreach (glob(googleTtsKeyDir().'/*') ?: [] as $path) {
        @unlink($path);
    }
});

it('sends exactly the body of §9.2 with a self-signed RS256 JWT and returns the mp3', function (): void {
    googleTtsKeyFile();
    $options = [];
    Http::fake(function (HttpRequest $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(googleTtsOk('ID3-the-mp3'), 200);
    });

    $audio = googleTts()->synthesize('ice cream', GOOGLE_TTS_VOICE);

    expect($audio)->toBe(['body' => 'ID3-the-mp3', 'content_type' => 'audio/mpeg'])
        ->and($options['timeout'] ?? null)->toBe(20)
        ->and($options['connect_timeout'] ?? null)->toBe(5)
        ->and(googleTtsUsage())->toBe(['characters' => 9, 'requests' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://texttospeech.googleapis.com/v1/text:synthesize')
            ->and(json_decode($request->body(), true))->toBe([
                'input' => ['text' => 'ice cream'],
                'voice' => ['languageCode' => 'en-US', 'name' => GOOGLE_TTS_VOICE],
                'audioConfig' => ['audioEncoding' => 'MP3', 'sampleRateHertz' => 24000],
            ]);

        $jwt = googleTtsBearer($request);
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);
        $claims = (array) JWT::decode($jwt, new Key(googleTtsKeyPair()['public'], 'RS256'));

        expect($header)->toBe(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => GOOGLE_TTS_KID])
            ->and($claims['iss'])->toBe(GOOGLE_TTS_EMAIL)
            ->and($claims['sub'])->toBe(GOOGLE_TTS_EMAIL)
            ->and($claims['aud'])->toBe('https://texttospeech.googleapis.com/')
            ->and($claims['exp'] - $claims['iat'])->toBe(3600)
            ->and($claims['iat'])->toBeLessThanOrEqual(time() - 30)
            ->and($claims)->not->toHaveKey('scope');

        return true;
    });
});

it('reuses the signed JWT and signs a new one when less than 5 minutes are left', function (): void {
    googleTtsKeyFile();
    $account = app(GoogleServiceAccount::class);

    $first = $account->bearerToken();
    $this->travel(50)->minutes();
    expect($account->bearerToken())->toBe($first);

    $this->travel(6)->minutes();
    expect($account->bearerToken())->not->toBe($first);
});

it('takes the languageCode from the voice name', function (string $voice, string $language): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response(googleTtsOk(), 200)]);

    googleTts()->synthesize('hello', $voice);

    Http::assertSent(fn (HttpRequest $request): bool => $request['voice'] === ['languageCode' => $language, 'name' => $voice]);
})->with([
    'Chirp 3 HD' => ['en-US-Chirp3-HD-Charon', 'en-US'],
    'Neural2' => ['en-GB-Neural2-A', 'en-GB'],
    'three-letter language' => ['cmn-CN-Chirp3-HD-Aoede', 'cmn-CN'],
]);

it('refuses a voice that is not Google\'s with tts_voice_unsupported, before the key, the pace and the budget', function (string $voice): void {
    // No key file at all: the voice is refused first.
    Http::fake();

    $e = googleTtsError('hello', $voice);

    expect($e->getMessage())->toBe(SpeechSynthesisException::VOICE_UNSUPPORTED)
        ->and($e->retryable)->toBeFalse()
        ->and(googleTtsUsage())->toBeNull()
        ->and(RateLimiter::attempts(TtsRequestThrottle::KEY))->toBe(0);
    Http::assertNothingSent();
})->with([
    'edge-tts name' => ['en-US-AriaNeural'],
    'edge-tts multilingual' => ['en-US-AvaMultilingualNeural'],
    'no language' => ['Charon'],
    'lower-case region' => ['en-us-Neural2-A'],
    'unknown family' => ['en-US-Journey-F'],
    'family without a name' => ['en-US-Neural2'],
    'empty voice' => [''],
    'a path in the voice' => ['en-US-../../x'],
    'a path after the family' => ['en-US-Neural2-../x'],
]);

it('accepts the voices of every Google family', function (string $voice): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response(googleTtsOk(), 200)]);

    expect(googleTts()->synthesize('hello', $voice)['body'])->toBe('ID3-mp3-bytes')
        ->and(GoogleSpeechSynthesizer::supportsVoice($voice))->toBeTrue();
})->with([
    'en-US-Neural2-F', 'en-US-Chirp3-HD-Charon', 'en-US-Chirp-HD-D', 'en-US-Wavenet-D', 'en-US-Standard-C',
    'en-US-Studio-O', 'en-US-News-K', 'en-US-Polyglot-1', 'en-US-Casual-K', 'cmn-CN-Chirp3-HD-Aoede',
]);

it('rejects a too long or empty text without calling Google or touching the budget', function (string $text, string $voice): void {
    googleTtsKeyFile();
    Http::fake();

    $e = googleTtsError($text, $voice);

    expect($e->getMessage())->toBe('tts_request_rejected')
        ->and($e->retryable)->toBeFalse()
        ->and(googleTtsUsage())->toBeNull();
    Http::assertNothingSent();
})->with([
    '5001 bytes' => [str_repeat('a', 5001), GOOGLE_TTS_VOICE],
    '2501 two-byte characters (5002 bytes)' => [str_repeat('é', 2501), GOOGLE_TTS_VOICE],
    'empty text' => ['', GOOGLE_TTS_VOICE],
]);

it('accepts a text of exactly 5000 bytes', function (): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response(googleTtsOk(), 200)]);

    expect(googleTts()->synthesize(str_repeat('a', 5000), GOOGLE_TTS_VOICE)['body'])->toBe('ID3-mp3-bytes');
});

it('answers a 401 by signing a new JWT and repeating once', function (): void {
    googleTtsKeyFile();
    $tokens = [];
    Http::fake(function (HttpRequest $request) use (&$tokens) {
        $tokens[] = googleTtsBearer($request);
        if (count($tokens) === 1) {
            test()->travel(5)->seconds(); // a later second: the new JWT differs

            return Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401);
        }

        return Http::response(googleTtsOk(), 200);
    });

    expect(googleTts()->synthesize('hello', GOOGLE_TTS_VOICE)['body'])->toBe('ID3-mp3-bytes')
        ->and($tokens)->toHaveCount(2)
        ->and($tokens[1])->not->toBe($tokens[0])
        ->and(googleTtsUsage())->toBe(['characters' => 5, 'requests' => 1])
        ->and(Cache::get(GoogleSpeechSynthesizer::HALT_KEY))->toBeNull();
});

it('a second 401 is the final tts_auth_failed: the characters come back, the driver halts and the next call sends nothing', function (): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED', 'message' => 'secret text hello']], 401)]);

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_auth_failed')
        ->and($e->retryable)->toBeFalse()
        ->and(googleTtsUsage())->toBe(['characters' => 0, 'requests' => 0])
        ->and(Cache::get(GoogleSpeechSynthesizer::HALT_KEY))->toBe(['code' => 'tts_auth_failed']);
    Http::assertSentCount(2);

    $again = googleTtsError('world');
    expect($again->getMessage())->toBe('tts_auth_failed');
    Http::assertSentCount(2);

    // The halt ends after 10 minutes.
    $this->travel(601)->seconds();
    googleTtsFake(['*' => Http::response(googleTtsOk(), 200)]);
    expect(googleTts()->synthesize('world', GOOGLE_TTS_VOICE)['body'])->toBe('ID3-mp3-bytes');
});

it('ignores a halt marker that is not the array it writes', function (): void {
    googleTtsKeyFile();
    Cache::put(GoogleSpeechSynthesizer::HALT_KEY, 'tts_forbidden', 600);
    Http::fake(['*' => Http::response(googleTtsOk(), 200)]);

    expect(googleTts()->synthesize('hello', GOOGLE_TTS_VOICE)['body'])->toBe('ID3-mp3-bytes');
});

it('classifies the answers of Google by §9.2 and gives the characters back only when Google surely did not synthesize', function (int $status, string $code, bool $retryable, bool $refunded, bool $halts): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response(['error' => ['code' => $status, 'status' => 'SOME_STATUS', 'message' => 'about hello']], $status)]);

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe($code)
        ->and($e->retryable)->toBe($retryable)
        ->and($e->getPrevious())->toBeNull()
        ->and(googleTtsUsage())->toBe($refunded ? ['characters' => 0, 'requests' => 0] : ['characters' => 5, 'requests' => 1])
        ->and(Cache::get(GoogleSpeechSynthesizer::HALT_KEY))->toBe($halts ? ['code' => $code] : null);
    Http::assertSentCount(1);
})->with([
    '400' => [400, 'tts_request_rejected', false, true, false],
    '404' => [404, 'tts_request_rejected', false, true, false],
    '422 (other 4xx)' => [422, 'tts_request_rejected', false, true, false],
    '403' => [403, 'tts_forbidden', false, true, true],
    '408' => [408, 'tts_rate_limited', true, true, false],
    '429' => [429, 'tts_rate_limited', true, true, false],
    '500' => [500, 'tts_service_failed', true, true, false],
    '503' => [503, 'tts_service_failed', true, true, false],
]);

it('a dropped connection or a timeout is the retryable tts_service_failed and keeps the characters (Google may have counted them)', function (): void {
    googleTtsKeyFile();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_service_failed')
        ->and($e->retryable)->toBeTrue()
        ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and(googleTtsUsage())->toBe(['characters' => 5, 'requests' => 1]);
});

it('a 200 without usable audio is the retryable tts_service_bad_audio and keeps the characters', function (array $body): void {
    googleTtsKeyFile();
    Http::fake(['*' => Http::response($body, 200)]);

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_service_bad_audio')
        ->and($e->retryable)->toBeTrue()
        ->and(googleTtsUsage())->toBe(['characters' => 5, 'requests' => 1]);
})->with([
    'not base64' => [['audioContent' => '!!! not base64 ***']],
    'empty' => [['audioContent' => '']],
    'missing' => [['something' => 'else']],
    'not a string' => [['audioContent' => ['x']]],
]);

it('logs only the HTTP code, the status enum of Google and the length of a refused request', function (): void {
    googleTtsKeyFile();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });
    Http::fake(['*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'bad text: confidential sentence']], 400)]);

    googleTtsError('confidential sentence');

    expect($logged)->toHaveCount(1)
        ->and($logged[0]->message)->toBe('tts.google_refused')
        ->and($logged[0]->context)->toBe(['status' => 400, 'error_status' => 'INVALID_ARGUMENT', 'chars' => 21]);
});

it('without GOOGLE_TTS_CREDENTIALS it is tts_service_not_configured, final, nothing is sent', function (): void {
    Http::fake();

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_service_not_configured')
        ->and($e->retryable)->toBeFalse()
        ->and(googleTtsUsage())->toBeNull();
    Http::assertNothingSent();
});

it('refuses a key file that is not a service-account RSA key: tts_credentials_invalid, no previous, nothing sent or reserved', function (Closure $file): void {
    $file();
    Http::fake();

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_credentials_invalid')
        ->and($e->retryable)->toBeFalse()
        ->and($e->getPrevious())->toBeNull()
        ->and(googleTtsUsage())->toBeNull();
    Http::assertNothingSent();
})->with([
    'no file' => [fn () => config(['twa.tts.google.credentials' => sys_get_temp_dir().'/google-tts-missing-'.uniqid().'.json'])],
    'a directory' => [fn () => config(['twa.tts.google.credentials' => sys_get_temp_dir()])],
    'empty file' => [fn () => googleTtsKeyFile('')],
    'larger than 16 KB' => [fn () => googleTtsKeyFile(json_encode(['type' => 'service_account', 'pad' => str_repeat('x', 17_000)]))],
    'not JSON' => [fn () => googleTtsKeyFile('{"type": "service_account", ')],
    'JSON, not an object' => [fn () => googleTtsKeyFile('"service_account"')],
    'not a service account' => [fn () => googleTtsKeyFile(override: ['type' => 'authorized_user'])],
    'bad client_email' => [fn () => googleTtsKeyFile(override: ['client_email' => 'not-an-email'])],
    'bad private_key_id' => [fn () => googleTtsKeyFile(override: ['private_key_id' => 'abc-123'])],
    'empty private_key_id' => [fn () => googleTtsKeyFile(override: ['private_key_id' => ''])],
    'private_key not a key' => [fn () => googleTtsKeyFile(override: ['private_key' => "-----BEGIN PRIVATE KEY-----\ngarbage\n-----END PRIVATE KEY-----\n"])],
    'private_key not a string' => [fn () => googleTtsKeyFile(override: ['private_key' => ['x']])],
    'an EC key, not RSA' => [function (): void {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $pem);
        googleTtsKeyFile(override: ['private_key' => $pem]);
    }],
]);

it('reads the key file again when the configured path changes', function (): void {
    googleTtsKeyFile();
    $account = app(GoogleServiceAccount::class);
    expect($account->bearerToken())->toBeString();

    googleTtsKeyFile(override: ['type' => 'authorized_user']);

    expect(fn () => $account->bearerToken())->toThrow(SpeechSynthesisException::class, 'tts_credentials_invalid');
});

it('the service account shows only its email in a dump and cannot be serialized', function (): void {
    googleTtsKeyFile();
    $account = app(GoogleServiceAccount::class);
    $token = $account->bearerToken();

    $dump = print_r($account, true).var_export($account->__debugInfo(), true);
    expect($dump)->toContain(GOOGLE_TTS_EMAIL)
        ->not->toContain($token)
        ->not->toContain('PRIVATE KEY')
        ->and(fn () => serialize($account))->toThrow(LogicException::class);
});

it('never lets the private key or the JWT into an exception message, its previous, a log line or audio_error', function (): void {
    $keyFile = googleTtsKeyFile();
    $pem = googleTtsKeyPair()['private'];
    // A distinctive piece of the key body, in case a log escapes the PEM differently.
    $keyBody = substr(str_replace("\n", '', $pem), 100, 48);
    $jwts = [];
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $answers = [
        fn () => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401),
        fn () => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        fn () => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        fn () => Http::response(['error' => ['status' => 'INVALID_ARGUMENT']], 400),
        fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
        fn () => Http::response(['audioContent' => '***'], 200),
    ];
    $exceptions = [];
    foreach ($answers as $answer) {
        Cache::forget(GoogleSpeechSynthesizer::HALT_KEY);
        googleTtsFake(function (HttpRequest $request) use (&$jwts, $answer) {
            $jwts[] = googleTtsBearer($request);

            return $answer();
        });
        $exceptions[] = googleTtsError('hello');
    }

    // A file that carries the key but is no service account.
    googleTtsKeyFile(override: ['type' => 'user']);
    $exceptions[] = googleTtsError('hello');
    config(['twa.tts.google.credentials' => $keyFile]);

    // A dictation that fails through the job: audio_error and the job log.
    Storage::fake('practice_audio');
    config(['queue.default' => 'sync']);
    googleTtsFake(function (HttpRequest $request) use (&$jwts) {
        $jwts[] = googleTtsBearer($request);

        return Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403);
    });
    Cache::forget(GoogleSpeechSynthesizer::HALT_KEY);
    $version = PracticeItemVersion::factory()->dictation()->audioPending()->create();
    GeneratePracticeAudioJob::dispatch($version->id);
    $failed = $version->fresh();

    expect($jwts)->not->toBeEmpty()
        ->and($failed?->audio_status)->toBe(AudioStatus::Failed)
        ->and($failed?->audio_error)->toBe('tts_forbidden');

    $texts = [(string) $failed?->audio_error, ...$logged];
    foreach ($exceptions as $e) {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $texts[] = $cause->getMessage();
        }
    }
    $haystack = implode("\n", $texts);
    expect($haystack)->not->toContain($keyBody)->not->toContain('PRIVATE KEY');
    foreach (array_unique($jwts) as $jwt) {
        expect($haystack)->not->toContain($jwt)
            // the signature alone, in case the token is cut
            ->not->toContain(explode('.', $jwt)[2]);
    }
});

it('waits for the pace limit before reserving and calling: tts_rate_limited after 30 seconds, nothing reserved or sent', function (): void {
    googleTtsKeyFile();
    config(['twa.tts.rate_per_minute' => 1]);
    Sleep::fake();
    RateLimiter::hit(TtsRequestThrottle::KEY, 60);
    Http::fake();

    $e = googleTtsError('hello');

    expect($e->getMessage())->toBe('tts_rate_limited')
        ->and($e->retryable)->toBeTrue()
        ->and(googleTtsUsage())->toBeNull();
    Http::assertNothingSent();
    Sleep::assertSleptTimes(6);
});

it('checks the monthly limit before calling: one character over is tts_monthly_limit_reached without HTTP', function (): void {
    googleTtsKeyFile();
    config(['twa.tts.monthly_char_limit' => 10]);
    Http::fake(['*' => Http::response(googleTtsOk(), 200)]);

    googleTts()->synthesize('hello', GOOGLE_TTS_VOICE);
    googleTts()->synthesize('world', GOOGLE_TTS_VOICE); // exactly the limit
    $e = googleTtsError('a');

    expect($e->getMessage())->toBe('tts_monthly_limit_reached')
        ->and($e->retryable)->toBeFalse()
        ->and(googleTtsUsage())->toBe(['characters' => 10, 'requests' => 2]);
    Http::assertSentCount(2);
});

it('gives the characters back to the month of the reservation when the answer comes in the next month', function (): void {
    googleTtsKeyFile();
    // 23:59:50 on 30 November in Los Angeles is already 1 December in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59:50', 'America/Los_Angeles'));
    Http::fake(function () {
        test()->travelTo(CarbonImmutable::parse('2026-12-01 00:00:20', 'America/Los_Angeles'));

        return Http::response(['error' => ['status' => 'UNAVAILABLE']], 503);
    });
    DB::table('tts_usage_months')->insert(['month' => '2026-11', 'characters' => 100, 'requests' => 4, 'created_at' => now(), 'updated_at' => now()]);

    expect(googleTtsError('hello')->getMessage())->toBe('tts_service_failed')
        ->and(DB::table('tts_usage_months')->where('month', '2026-11')->value('characters'))->toEqual(100)
        ->and(DB::table('tts_usage_months')->where('month', '2026-12')->exists())->toBeFalse();
});
