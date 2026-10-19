<?php

declare(strict_types=1);

use App\Domain\Operations\Support\RedactSecretsInLogs;
use App\Domain\Operations\Support\SecretRedactor;
use App\Domain\Operations\Support\SentryScrubber;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\HandleTelegramUpdate;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Sentry\Logs\Log as SentryLog;
use Sentry\Logs\LogLevel;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\HttpClients\GuzzleHttpClient;

uses(RefreshDatabase::class);

const REDACTION_TOKEN = '123456789:AAEhBP0av18UbLNvPLhm4RxQ0l7hTqlJkP8';

/** Queue job that talks to Bot API through the real SDK + Guzzle stack and fails with a network error. */
final class RedactionProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(IrazasyedTelegramClient $telegram): void
    {
        $telegram->sendImmediately(1, 'hi');
    }
}

/** The error Guzzle's cURL handler produces on a timeout: it embeds the full Bot API URL. */
function redactionNetworkError(string $token = REDACTION_TOKEN): ConnectException
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    return new ConnectException(
        "cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$url}",
        new Request('POST', $url),
    );
}

/** Telegram client over the real SDK whose HTTP layer fails like a network timeout. */
function redactionClientWithNetworkError(): IrazasyedTelegramClient
{
    $http = new GuzzleHttpClient(new Client([
        'handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, redactionNetworkError()))),
    ]));

    return new IrazasyedTelegramClient(
        new Api(REDACTION_TOKEN, false, $http),
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    );
}

/** SDK-shaped exception chain: TelegramSDKException(message with URL) <- ConnectException(same). */
function redactionSdkException(): TelegramSDKException
{
    $guzzle = redactionNetworkError();

    return new TelegramSDKException($guzzle->getMessage(), 0, $guzzle);
}

function redactionLogFile(): string
{
    $dir = storage_path('framework/testing');
    File::ensureDirectoryExists($dir);

    return $dir.'/redaction-'.bin2hex(random_bytes(4)).'.log';
}

// --- SecretRedactor ----------------------------------------------------------

it('redacts bot token, configured secrets, webhook segment and bearer tokens in text', function (): void {
    config(['telegram.bot_token' => 'literal-bot-token-value', 'twa.jwt.secret' => 'jwt-secret-value-0123456789']);

    $text = 'POST https://api.telegram.org/bot'.REDACTION_TOKEN.'/sendMessage; literal-bot-token-value; '
        .'jwt-secret-value-0123456789; /telegram/webhook/whsec_AbCdEf0123456789xyz?x=1; Authorization: Bearer eyJhbGci.payload.sig';

    $out = SecretRedactor::redact($text);

    expect($out)->not->toContain('AAEhBP0av18U')
        ->and($out)->not->toContain('literal-bot-token-value')
        ->and($out)->not->toContain('jwt-secret-value-0123456789')
        ->and($out)->not->toContain('whsec_AbCdEf')
        ->and($out)->not->toContain('eyJhbGci')
        ->and($out)->toContain('api.telegram.org/bot[redacted]/sendMessage');
});

it('keeps SentryScrubber::scrubString on the shared redactor', function (): void {
    expect(SentryScrubber::scrubString('x '.REDACTION_TOKEN))->toBe(SecretRedactor::redact('x '.REDACTION_TOKEN))
        ->and(SentryScrubber::scrubString('x '.REDACTION_TOKEN))->not->toContain('AAEhBP');
});

it('describes a throwable without secrets, call arguments or the raw previous message', function (): void {
    $text = SecretRedactor::describeThrowable(redactionSdkException());

    expect($text)->not->toContain(REDACTION_TOKEN)
        ->and($text)->not->toContain('AAEhBP')
        ->and($text)->toContain(TelegramSDKException::class)
        ->and($text)->toContain(ConnectException::class)
        ->and($text)->toContain('cURL error 28')
        ->and($text)->toContain('[stacktrace]');
});

// --- Sentry Logs ---------------------------------------------------------------

it('scrubs Sentry Logs body and attributes in before_send_log', function (): void {
    $log = new SentryLog(microtime(true), 'trace', LogLevel::error(), 'failed for https://api.telegram.org/bot'.REDACTION_TOKEN.'/sendMessage');
    $log->setAttribute('url', 'https://api.telegram.org/bot'.REDACTION_TOKEN.'/getMe');
    $log->setAttribute('authorization', 'Bearer abc.def.ghi');
    $log->setAttribute('attempt', 3);

    $out = SentryScrubber::beforeSendLog($log);

    expect($out->getBody())->not->toContain('AAEhBP')
        ->and(json_encode($out->attributes()->toSimpleArray()))->not->toContain('AAEhBP')->not->toContain('abc.def.ghi')
        ->and($out->attributes()->get('attempt')?->getValue())->toBe(3);
    expect(config('sentry.before_send_log'))->toBe([SentryScrubber::class, 'beforeSendLog']);
});

// --- Log processor ---------------------------------------------------------------

it('redacts message, context and exceptions on a tapped channel (processor on the real record)', function (): void {
    config(['logging.channels.redaction_probe' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'tap' => [RedactSecretsInLogs::class],
        'level' => 'debug',
    ]]);

    $logger = Log::channel('redaction_probe');
    $logger->error('Failed https://api.telegram.org/bot'.REDACTION_TOKEN.'/sendMessage', [
        'exception' => redactionSdkException(),
        'nested' => ['url' => 'https://api.telegram.org/bot'.REDACTION_TOKEN.'/getMe', 'n' => 5],
        'password' => 'hunter2',
    ]);

    /** @var TestHandler $handler */
    $handler = $logger->getLogger()->getHandlers()[0];
    $record = $handler->getRecords()[0];
    $dump = json_encode([$record->message, $record->context, $record->extra], JSON_THROW_ON_ERROR);

    expect($dump)->not->toContain('AAEhBP')
        ->and($dump)->not->toContain('hunter2')
        ->and($record->message)->toContain('bot[redacted]')
        ->and($record->context['exception'])->toBeString()->toContain(TelegramSDKException::class)
        ->and($record->context['nested']['n'])->toBe(5);
});

it('keeps the token out of the formatted laravel.log line for stack and single channels', function (): void {
    $file = redactionLogFile();
    config([
        'logging.channels.single.path' => $file,
        'logging.channels.stack.channels' => ['single'],
        'logging.default' => 'stack',
    ]);
    app('log')->forgetChannel('single');
    app('log')->forgetChannel('stack');

    Log::error('boom '.REDACTION_TOKEN, ['exception' => redactionSdkException(), 'update_id' => 77]);
    Log::channel('single')->warning('direct', ['exception' => redactionSdkException()]);

    $content = (string) file_get_contents($file);
    File::delete($file);

    expect($content)->toContain('boom')
        ->and($content)->toContain('direct')
        ->and($content)->toContain('cURL error 28')
        ->and($content)->toContain('[stacktrace]')
        ->and($content)->not->toContain(REDACTION_TOKEN)
        ->and($content)->not->toContain('AAEhBP');
});

it('redacts placeholders substituted from the context', function (): void {
    $file = redactionLogFile();
    config(['logging.channels.single.path' => $file]);
    app('log')->forgetChannel('single');

    Log::channel('single')->info('called {url}', ['url' => 'https://api.telegram.org/bot'.REDACTION_TOKEN.'/getMe']);

    $content = (string) file_get_contents($file);
    File::delete($file);

    expect($content)->toContain('called https://api.telegram.org/bot[redacted]/getMe')
        ->and($content)->not->toContain('AAEhBP');
});

it('logs a redacted message from HandleTelegramUpdate when the handler fails with a token in the message', function (): void {
    $file = redactionLogFile();
    config(['logging.channels.daily.path' => $file, 'logging.channels.daily.days' => 1]);
    app('log')->forgetChannel('daily');

    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendMessage')->once()
        ->andThrow(new RuntimeException('failed for https://api.telegram.org/bot'.REDACTION_TOKEN.'/sendMessage'));
    $this->app->instance(TelegramClient::class, $client);

    $update = ['update_id' => 5, 'message' => ['text' => '/help', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'language_code' => 'ru']]];
    $thrown = null;
    try {
        (new HandleTelegramUpdate($update))->handle(app(TelegramDispatcher::class));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    // The daily driver appends the date to the file name.
    $files = glob(dirname($file).'/'.pathinfo($file, PATHINFO_FILENAME).'-*.log') ?: [];
    $content = implode('', array_map(fn (string $f): string => (string) file_get_contents($f), $files));
    array_map(unlink(...), $files);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($content)->toContain('telegram.handler_failed')
        ->and($content)->toContain('bot[redacted]')
        ->and($content)->not->toContain('AAEhBP');
});

// --- Telegram client boundary ----------------------------------------------------

it('wraps SDK network errors in TelegramApiException without the token, previous or raw message', function (): void {
    $client = redactionClientWithNetworkError();

    try {
        $client->sendImmediately(1, 'hi');
        $this->fail('Expected TelegramApiException');
    } catch (TelegramApiException $e) {
        expect($e->getMessage())->toContain('cURL error 28')->toContain(TelegramSDKException::class)
            ->and($e->getPrevious())->toBeNull()
            ->and((string) $e)->not->toContain(REDACTION_TOKEN)->not->toContain('AAEhBP');
    }
});

it('covers every SDK call path of the client', function (): void {
    $sdk = new class extends Api
    {
        public function __construct()
        {
            parent::__construct('test-token');
        }

        /** @param array<string, mixed> $params */
        public function sendMessage(array $params): never
        {
            throw redactionSdkException();
        }

        /** @param array<string, mixed> $params */
        public function answerCallbackQuery(array $params): never
        {
            throw redactionSdkException();
        }

        /** @param array<string, mixed> $params */
        public function setWebhook(array $params): never
        {
            throw redactionSdkException();
        }

        public function deleteWebhook(array $params = []): never
        {
            throw redactionSdkException();
        }
    };
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $calls = [
        'sendImmediately' => fn () => $client->sendImmediately(1, 'hi'),
        'sendMessage' => fn () => $client->sendMessage(2, 'hi'),
        'sendWebAppButton' => fn () => $client->sendWebAppButton(3, 'hi', 'Open', 'https://twa.test'),
        'answerCallbackQuery' => fn () => $client->answerCallbackQuery('cb'),
        'setWebhook' => fn () => $client->setWebhook('https://x.test/telegram/webhook/s', 'hdr'),
        'deleteWebhook' => fn () => $client->deleteWebhook(),
    ];

    foreach ($calls as $name => $call) {
        try {
            $call();
            $this->fail("{$name} did not throw");
        } catch (TelegramApiException $e) {
            expect((string) $e)->not->toContain('AAEhBP', $name)
                ->and($e->getPrevious())->toBeNull();
        }
    }
});

it('keeps mapping Telegram 403 to TelegramForbiddenException', function (): void {
    $http = new GuzzleHttpClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([
            new Response(403, [], json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], JSON_THROW_ON_ERROR)),
        ])),
        'http_errors' => false,
    ]));
    $client = new IrazasyedTelegramClient(new Api(REDACTION_TOKEN, false, $http), new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    expect(fn () => $client->sendImmediately(1, 'hi'))->toThrow(TelegramForbiddenException::class);
});

it('maps 400 "chat not found" to TelegramForbiddenException (a dialog that is gone), but not other 400s', function (): void {
    $client = fn (string $description): IrazasyedTelegramClient => new IrazasyedTelegramClient(
        new Api(REDACTION_TOKEN, false, new GuzzleHttpClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(400, [], json_encode(['ok' => false, 'error_code' => 400, 'description' => $description], JSON_THROW_ON_ERROR)),
            ])),
            'http_errors' => false,
        ]))),
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    );

    try {
        $client('Bad Request: chat not found')->sendImmediately(1, 'hi');
        $this->fail('chat not found did not throw');
    } catch (TelegramForbiddenException $e) {
        expect($e->reason)->toBe(TelegramForbiddenException::REASON_CHAT_NOT_FOUND);
    }

    expect(fn () => $client('Bad Request: message text is empty')->sendImmediately(1, 'hi'))
        ->toThrow(TelegramApiException::class);
});

// --- failed_jobs -------------------------------------------------------------------

it('does not store the bot token in failed_jobs nor in the log when a queued job dies on a network error', function (): void {
    $file = redactionLogFile();
    config([
        'queue.default' => 'database',
        'logging.channels.single.path' => $file,
        'logging.channels.stack.channels' => ['single'],
        'logging.default' => 'stack',
    ]);
    app('log')->forgetChannel('single');
    app('log')->forgetChannel('stack');

    $http = new GuzzleHttpClient(new Client([
        'handler' => HandlerStack::create(new MockHandler([redactionNetworkError()])),
    ]));
    app()->instance(IrazasyedTelegramClient::class, new IrazasyedTelegramClient(
        new Api(REDACTION_TOKEN, false, $http),
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    ));

    RedactionProbeJob::dispatch();
    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);

    $failed = DB::table('failed_jobs')->first();
    $content = is_file($file) ? (string) file_get_contents($file) : '';
    File::delete($file);

    expect($failed)->not->toBeNull()
        ->and($failed->exception)->toContain('cURL error 28')
        ->and($failed->exception)->toContain(TelegramApiException::class)
        ->and($failed->exception)->not->toContain(REDACTION_TOKEN)
        ->and($failed->exception)->not->toContain('AAEhBP')
        ->and($content)->not->toContain('AAEhBP');
});
