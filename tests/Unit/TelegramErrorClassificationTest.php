<?php

declare(strict_types=1);

use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

/*
 * docs/34 §4в п. 1 (B1): what each Bot API answer becomes at the TelegramClient boundary. The SDK double throws exactly what the
 * irazasyed SDK throws for a Telegram answer (TelegramResponseException over the decoded body) or for a transport failure (a
 * Guzzle/cURL exception whose message embeds the request URL with the token).
 */

const CLASSIFY_TOKEN = '123456789:AAHsecretsecretsecretsecretsecret0042';

beforeEach(function (): void {
    Cache::flush();
    config(['telegram.bot_token' => CLASSIFY_TOKEN]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00'));
});

/** @param array<string, mixed> $body */
function tgAnswer(int $status, array $body, string $endpoint = 'sendMessage'): TelegramResponseException
{
    return new TelegramResponseException(new TelegramResponse(
        new TelegramRequest(CLASSIFY_TOKEN, 'POST', $endpoint),
        new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)),
    ));
}

function tgTimeout(string $endpoint = 'sendMessage'): RuntimeException
{
    return new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds for https://api.telegram.org/bot'.CLASSIFY_TOKEN.'/'.$endpoint);
}

/**
 * An SDK whose every call takes the next scripted outcome: a Throwable is thrown, anything else is a success (the params are kept).
 *
 * @param  list<Throwable|null>  $script
 */
/** The SDK's failure for each kind of answer, every one with the token where a careless message would show it. */
function sdkFailure(string $kind): Throwable
{
    $url = 'https://api.telegram.org/bot'.CLASSIFY_TOKEN.'/sendMessage';

    return match ($kind) {
        '429' => tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 3 '.$url, 'parameters' => ['retry_after' => 3]]),
        '403' => tgAnswer(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked '.$url]),
        '400 other' => tgAnswer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request for '.$url]),
        '5xx' => tgAnswer(502, ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway at '.$url]),
        'timeout' => tgTimeout(),
    };
}

function scriptedSdk(array $script): Api
{
    return new class($script) extends Api
    {
        /** @var list<array{method: string, params: array<string, mixed>}> */
        public array $calls = [];

        /** @param list<Throwable|null> $script */
        public function __construct(private array $script)
        {
            parent::__construct(CLASSIFY_TOKEN);
        }

        /** @param array<string, mixed> $params */
        private function next(string $method, array $params): void
        {
            $this->calls[] = ['method' => $method, 'params' => $params];
            $outcome = array_shift($this->script);
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }
        }

        /** @param array<string, mixed> $params */
        public function sendMessage(array $params): MessageObject
        {
            $this->next('sendMessage', $params);

            return new MessageObject([]);
        }

        /** @param array<string, mixed> $params */
        public function answerCallbackQuery(array $params): bool
        {
            $this->next('answerCallbackQuery', $params);

            return true;
        }

        /** @param array<string, mixed> $params */
        public function editMessageText(array $params): MessageObject
        {
            $this->next('editMessageText', $params);

            return new MessageObject([]);
        }
    };
}

function classifyingClient(Api $sdk, ?TelegramMessageRateLimiter $limiter = null): IrazasyedTelegramClient
{
    return new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, $limiter ?? new TelegramMessageRateLimiter);
}

function caught(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('expected an exception');
}

it('turns a 429 into TelegramRateLimitedException with retry_after: a definite refusal that old catch blocks still catch', function (): void {
    $client = classifyingClient(scriptedSdk([tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 17', 'parameters' => ['retry_after' => 17]])]));

    $e = caught(fn () => $client->sendImmediately(555, 'hi'));

    expect($e)->toBeInstanceOf(TelegramRateLimitedException::class)
        ->toBeInstanceOf(TelegramApiException::class)
        ->and($e->retryAfter)->toBe(17)
        ->and($e->isApiResponse())->toBeTrue()
        ->and($e->getCode())->toBe(429)
        ->and($e->getPrevious())->toBeNull();
});

it('classifies a 429 on every method, not only on sends', function (string $method): void {
    $limited = tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 4', 'parameters' => ['retry_after' => 4]], $method);
    $client = classifyingClient(scriptedSdk([$limited]));

    $e = caught(fn () => match ($method) {
        'answerCallbackQuery' => $client->answerCallbackQuery('q1', 'ok'),
        'editMessageText' => $client->editMessageText(-100500, 7, 'new text'),
    });

    expect($e)->toBeInstanceOf(TelegramRateLimitedException::class)->and($e->retryAfter)->toBe(4);
})->with(['answerCallbackQuery', 'editMessageText']);

it('falls back to a short retry when a 429 carries no retry_after', function (): void {
    $client = classifyingClient(scriptedSdk([tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests'])]));

    $e = caught(fn () => $client->sendImmediately(555, 'hi'));

    expect($e)->toBeInstanceOf(TelegramRateLimitedException::class)
        ->and($e->retryAfter)->toBe(TelegramRateLimitedException::DEFAULT_RETRY_AFTER);
});

it('pauses the whole bot and the chat on a 429: reserveGlobal and reserve refuse until retry_after', function (): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 25);
    $client = classifyingClient(scriptedSdk([tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 9', 'parameters' => ['retry_after' => 9]])]), $limiter);

    caught(fn () => $client->sendImmediately(-100700, 'hi'));

    expect($limiter->reserveGlobal())->toBeFalse()
        ->and($limiter->reserve(-100700))->toBe(9)
        // Another chat is not paused for replies (only background sends wait for the bot-wide pause).
        ->and($limiter->reserve(-100701))->toBe(0);
});

it('turns a 403 of a send into TelegramForbiddenException, as before', function (): void {
    $client = classifyingClient(scriptedSdk([tgAnswer(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'])]));

    $e = caught(fn () => $client->sendImmediately(555, 'hi'));

    expect($e)->toBeInstanceOf(TelegramForbiddenException::class)
        ->and($e->reason)->toBe(TelegramForbiddenException::REASON_BOT_BLOCKED);
});

it('turns a 400 with migrate_to_chat_id of an edit into TelegramChatMigratedException carrying the new chat id', function (): void {
    $client = classifyingClient(scriptedSdk([tgAnswer(400, [
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: group chat was upgraded to a supergroup chat',
        'parameters' => ['migrate_to_chat_id' => -1001234567890],
    ], 'editMessageText')]));

    $e = caught(fn () => $client->editMessageText(-4242, 7, 'new text'));

    expect($e)->toBeInstanceOf(TelegramChatMigratedException::class)
        ->toBeInstanceOf(TelegramApiException::class)
        ->and($e->newChatId)->toBe(-1001234567890)
        ->and($e->isApiResponse())->toBeTrue()
        ->and($e->getMessage())->not->toContain('4242')->not->toContain('1001234567890');
});

it('keeps any other 400 an ordinary definite refusal', function (): void {
    $client = classifyingClient(scriptedSdk([tgAnswer(400, ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"])]));

    $e = caught(fn () => $client->sendImmediately(555, '<b>'));

    expect($e::class)->toBe(TelegramApiException::class)
        ->and($e->isApiResponse())->toBeTrue();
});

it('keeps a 5xx and a timeout "may have been delivered" (isApiResponse = false), with nothing paused', function (string $kind): void {
    $limiter = new TelegramMessageRateLimiter(globalPerSecond: 25);
    $client = classifyingClient(scriptedSdk([sdkFailure($kind)]), $limiter);

    $e = caught(fn () => $client->sendImmediately(555, 'hi'));

    expect($e::class)->toBe(TelegramApiException::class)
        ->and($e->isApiResponse())->toBeFalse()
        ->and($limiter->globalPauseRemaining())->toBe(0);
})->with(['5xx', 'timeout']);

it('leaks the bot token into no exception message and no log line, whatever the answer', function (string $kind): void {
    Log::spy();
    $client = classifyingClient(scriptedSdk([sdkFailure($kind)]));

    $e = caught(fn () => $client->sendImmediately(555, 'hi'));

    expect($e->getMessage())->not->toContain('AAHsecret')
        ->and((string) $e)->not->toContain('AAHsecret')
        ->and($e->getPrevious())->toBeNull();

    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []): bool => str_contains($message.json_encode($context), 'AAHsecret'));
    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains($message.json_encode($context), 'AAHsecret'));
})->with(['429', '403', '400 other', '5xx', 'timeout']);

it('still throws the 429 when the cache cannot store the pause', function (): void {
    Log::spy();
    $client = classifyingClient(scriptedSdk([tgAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 2]])]));
    Cache::shouldReceive('getStore')->andThrow(new RuntimeException('Redis went away'));

    expect(caught(fn () => $client->sendImmediately(555, 'hi')))->toBeInstanceOf(TelegramRateLimitedException::class);
    Log::shouldHaveReceived('warning')->with('telegram.rate_limit_pause_failed', Mockery::any())->once();
});
