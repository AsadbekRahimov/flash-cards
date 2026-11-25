<?php

declare(strict_types=1);

use App\Domain\Telegram\DTO\PreparedInlineMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;
use Tests\Support\FakeTelegramClient;

/*
 * docs/36 §1в, task H2: `savePreparedInlineMessage` (Bot API 8.0, the message of `WebApp.shareMessage`) and `answerInlineQuery`
 * through raw `post()` calls - the request (objects as JSON, flags only when true), the answer and the refusals at the
 * TelegramClient boundary; and the recording fake the next tasks test against.
 */

const INLINE_TOKEN = '123456789:AAHinlineinlineinlineinlineinline0042';

beforeEach(function (): void {
    Cache::flush();
});

/**
 * An SDK whose `post()` records the call and answers with `$body` (a JSON answer of the Bot API) or throws `$failure`.
 */
function inlineSdk(string $body = '{"ok":true,"result":true}', ?Throwable $failure = null): Api
{
    return new class($body, $failure) extends Api
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private readonly string $body, private readonly ?Throwable $failure)
        {
            parent::__construct(INLINE_TOKEN);
        }

        /** @param array<string, mixed> $params */
        public function post(string $endpoint, array $params = [], bool $fileUpload = false): TelegramResponse
        {
            $this->calls[] = [$endpoint, $params];
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return new TelegramResponse(new TelegramRequest(INLINE_TOKEN, 'POST', $endpoint, $params), new Response(200, [], $this->body));
        }
    };
}

function inlineClient(Api $sdk): IrazasyedTelegramClient
{
    return new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);
}

/** @param array<string, mixed> $body */
function inlineRefusal(int $status, array $body, string $endpoint): TelegramResponseException
{
    return new TelegramResponseException(new TelegramResponse(
        new TelegramRequest(INLINE_TOKEN, 'POST', $endpoint),
        new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)),
    ));
}

/** @return array<string, mixed> */
function inlinePhotoResult(): array
{
    return [
        'type' => 'photo',
        'id' => 'a:streak_7',
        'photo_url' => 'https://muhrla.test/storage/share-cards/streak_7-ru-0123456789abcdef0123.jpg',
        'thumbnail_url' => 'https://muhrla.test/storage/share-cards/streak_7-ru-0123456789abcdef0123.jpg',
        'caption' => '🏆 Я получил(а) достижение «Серия 7 дней» в Muhrla!',
        'reply_markup' => ['inline_keyboard' => [[['text' => '🚀 Начать изучение', 'url' => 'https://t.me/muhrla_bot?start=ref_ABC']]]],
    ];
}

function inlineCaught(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('expected an exception');
}

// --- savePreparedInlineMessage -------------------------------------------------------------------------------------

it('prepares an inline message: the result as JSON, the user, only the chat flags that are true; answers id and expiration_date', function (): void {
    $sdk = inlineSdk('{"ok":true,"result":{"id":"pm_1a2b3c","expiration_date":1764072000}}');

    $prepared = inlineClient($sdk)->savePreparedInlineMessage(
        userId: 555,
        result: inlinePhotoResult(),
        allowUserChats: true,
        allowGroupChats: true,
        allowChannelChats: true,
    );

    expect($prepared)->toBeInstanceOf(PreparedInlineMessage::class)
        ->and($prepared->id)->toBe('pm_1a2b3c')
        ->and($prepared->expirationDate)->toBe(1_764_072_000);

    expect($sdk->calls)->toHaveCount(1);
    [$endpoint, $params] = $sdk->calls[0];
    expect($endpoint)->toBe('savePreparedInlineMessage')
        // `allow_bot_chats` is false: not sent at all (Telegram's default).
        ->and(array_keys($params))->toBe(['user_id', 'result', 'allow_user_chats', 'allow_group_chats', 'allow_channel_chats'])
        ->and($params['user_id'])->toBe(555)
        ->and($params['allow_user_chats'])->toBeTrue()
        ->and($params['allow_group_chats'])->toBeTrue()
        ->and($params['allow_channel_chats'])->toBeTrue()
        // One JSON-serialized InlineQueryResult, unicode and slashes as they are.
        ->and($params['result'])->toBeString()
        ->and($params['result'])->toContain('"photo_url":"https://muhrla.test/storage/share-cards/')
        ->and($params['result'])->toContain('«Серия 7 дней»')
        ->and(json_decode((string) $params['result'], true, flags: JSON_THROW_ON_ERROR))->toBe(inlinePhotoResult());
});

it('sends no chat flag at all when none is allowed, and the bot chats flag when it is', function (): void {
    $sdk = inlineSdk('{"ok":true,"result":{"id":"x","expiration_date":1764072000}}');
    $client = inlineClient($sdk);

    $client->savePreparedInlineMessage(7, ['type' => 'article', 'id' => 'i', 'title' => 'T', 'input_message_content' => ['message_text' => 'Hi']]);
    $client->savePreparedInlineMessage(7, ['type' => 'article', 'id' => 'i', 'title' => 'T', 'input_message_content' => ['message_text' => 'Hi']], allowBotChats: true);

    expect(array_keys($sdk->calls[0][1]))->toBe(['user_id', 'result'])
        ->and(array_keys($sdk->calls[1][1]))->toBe(['user_id', 'result', 'allow_bot_chats']);
});

it('reports an ok answer without an id or an expiration date as a failure, not as a refusal', function (string $body): void {
    $e = inlineCaught(fn () => inlineClient(inlineSdk($body))->savePreparedInlineMessage(7, inlinePhotoResult(), allowUserChats: true));

    expect($e)->toBeInstanceOf(TelegramApiException::class)
        ->and($e->getMessage())->toContain('savePreparedInlineMessage')
        ->and($e instanceof TelegramApiException && $e->isApiResponse())->toBeFalse();
})->with([
    'no result' => ['{"ok":true,"result":true}'],
    'no id' => ['{"ok":true,"result":{"expiration_date":1764072000}}'],
    'empty id' => ['{"ok":true,"result":{"id":"","expiration_date":1764072000}}'],
    'no expiration' => ['{"ok":true,"result":{"id":"x"}}'],
    'expiration as a string' => ['{"ok":true,"result":{"id":"x","expiration_date":"soon"}}'],
]);

it('maps a refusal of savePreparedInlineMessage to TelegramApiException without the token', function (): void {
    $refusal = inlineRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: PHOTO_INVALID_DIMENSIONS for https://api.telegram.org/bot'.INLINE_TOKEN.'/savePreparedInlineMessage'], 'savePreparedInlineMessage');

    $e = inlineCaught(fn () => inlineClient(inlineSdk(failure: $refusal))->savePreparedInlineMessage(7, inlinePhotoResult(), allowUserChats: true));

    expect($e)->toBeInstanceOf(TelegramApiException::class)
        ->not->toBeInstanceOf(TelegramRateLimitedException::class)
        ->and($e->getMessage())->toContain('PHOTO_INVALID_DIMENSIONS')->not->toContain('AAHinline')
        ->and($e->getPrevious())->toBeNull()
        ->and($e instanceof TelegramApiException && $e->isApiResponse())->toBeTrue();
});

it('maps a transport failure to TelegramApiException without the token, not a definite refusal', function (): void {
    $timeout = new RuntimeException('cURL error 28: Operation timed out for https://api.telegram.org/bot'.INLINE_TOKEN.'/savePreparedInlineMessage');

    $e = inlineCaught(fn () => inlineClient(inlineSdk(failure: $timeout))->savePreparedInlineMessage(7, inlinePhotoResult(), allowUserChats: true));

    expect($e)->toBeInstanceOf(TelegramApiException::class)
        ->and($e->getMessage())->not->toContain('AAHinline')
        ->and($e instanceof TelegramApiException && $e->isApiResponse())->toBeFalse();
});

it('turns a 429 of either inline method into TelegramRateLimitedException with retry_after', function (string $method): void {
    $limited = inlineRefusal(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 6', 'parameters' => ['retry_after' => 6]], $method);
    $client = inlineClient(inlineSdk(failure: $limited));

    $e = inlineCaught(fn () => $method === 'answerInlineQuery'
        ? $client->answerInlineQuery('q1', [])
        : $client->savePreparedInlineMessage(7, inlinePhotoResult(), allowUserChats: true));

    expect($e)->toBeInstanceOf(TelegramRateLimitedException::class)
        ->and($e instanceof TelegramRateLimitedException ? $e->retryAfter : null)->toBe(6);
})->with(['savePreparedInlineMessage', 'answerInlineQuery']);

// --- answerInlineQuery -----------------------------------------------------------------------------------------------

it('answers an inline query: the results as a JSON array, cache_time, is_personal only when true', function (): void {
    $sdk = inlineSdk();
    $client = inlineClient($sdk);

    $client->answerInlineQuery('q-42', [inlinePhotoResult()], cacheTime: 0, isPersonal: true);
    $client->answerInlineQuery('q-43', []);

    [$endpoint, $params] = $sdk->calls[0];
    expect($endpoint)->toBe('answerInlineQuery')
        ->and(array_keys($params))->toBe(['inline_query_id', 'results', 'cache_time', 'is_personal'])
        ->and($params['inline_query_id'])->toBe('q-42')
        ->and($params['cache_time'])->toBe(0)
        ->and($params['is_personal'])->toBeTrue()
        ->and(json_decode((string) $params['results'], true, flags: JSON_THROW_ON_ERROR))->toBe([inlinePhotoResult()]);

    // An empty answer is a valid one (over the limit of inline queries); Telegram's default cache, not personal.
    expect($sdk->calls[1])->toBe(['answerInlineQuery', ['inline_query_id' => 'q-43', 'results' => '[]', 'cache_time' => 300]]);
});

it('never sends a negative cache_time', function (): void {
    $sdk = inlineSdk();
    inlineClient($sdk)->answerInlineQuery('q', [['type' => 'article', 'id' => 'i']], cacheTime: -5);

    expect($sdk->calls[0][1]['results'])->toBe('[{"type":"article","id":"i"}]')
        ->and($sdk->calls[0][1]['cache_time'])->toBe(0);
});

it('refuses more than 50 results before calling Telegram', function (): void {
    $sdk = inlineSdk();

    expect(fn () => inlineClient($sdk)->answerInlineQuery('q', array_fill(0, 51, ['type' => 'article', 'id' => 'i'])))
        ->toThrow(InvalidArgumentException::class);
    expect($sdk->calls)->toBe([]);
});

it('maps a refusal of answerInlineQuery ("query is too old") to TelegramApiException without the token', function (): void {
    $refusal = inlineRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid at https://api.telegram.org/bot'.INLINE_TOKEN.'/answerInlineQuery'], 'answerInlineQuery');

    $e = inlineCaught(fn () => inlineClient(inlineSdk(failure: $refusal))->answerInlineQuery('q', []));

    expect($e)->toBeInstanceOf(TelegramApiException::class)
        ->and($e->getMessage())->toContain('query is too old')->not->toContain('AAHinline')
        ->and($e instanceof TelegramApiException && $e->isApiResponse())->toBeTrue();
});

// --- the fake ----------------------------------------------------------------------------------------------------------

it('records prepared messages and inline answers in the fake, with scripted refusals', function (): void {
    $fake = new FakeTelegramClient;
    $fake->preparedExpirationDate = 1_764_072_000;

    $prepared = $fake->savePreparedInlineMessage(555, inlinePhotoResult(), allowUserChats: true, allowGroupChats: true);
    $fake->answerInlineQuery('q1', [inlinePhotoResult()], 0, true);

    expect($prepared->id)->toBe('prepared-1')
        ->and($prepared->expirationDate)->toBe(1_764_072_000)
        ->and($fake->preparedMessages[0])->toMatchArray(['user_id' => 555, 'allow_user_chats' => true, 'allow_bot_chats' => false, 'allow_group_chats' => true, 'allow_channel_chats' => false, 'id' => 'prepared-1'])
        ->and($fake->preparedMessages[0]['result'])->toBe(inlinePhotoResult())
        ->and($fake->inlineAnswers)->toBe([['inline_query_id' => 'q1', 'results' => [inlinePhotoResult()], 'cache_time' => 0, 'is_personal' => true]]);

    $fake->failInline = 1;
    $refused = inlineCaught(fn () => $fake->savePreparedInlineMessage(555, inlinePhotoResult()));
    expect($refused)->toBeInstanceOf(TelegramApiException::class)
        ->and($refused instanceof TelegramApiException && $refused->isApiResponse())->toBeTrue();

    $fake->failInline = 1;
    $fake->failInlineRetryAfter = 9;
    $limited = inlineCaught(fn () => $fake->answerInlineQuery('q2', []));
    expect($limited)->toBeInstanceOf(TelegramRateLimitedException::class);

    $fake->failInline = 1;
    $fake->failInlineRetryAfter = null;
    $fake->failInlineAsTransport = true;
    $transport = inlineCaught(fn () => $fake->savePreparedInlineMessage(555, inlinePhotoResult()));
    expect($transport instanceof TelegramApiException && ! $transport->isApiResponse())->toBeTrue();

    // The refusals are used up: the next call goes through, and the failed ones were not recorded.
    $fake->savePreparedInlineMessage(555, inlinePhotoResult());
    expect($fake->preparedMessages)->toHaveCount(2)->and($fake->inlineAnswers)->toHaveCount(1);
});
