<?php

declare(strict_types=1);

use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\DTO\SentMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\StreamInterface;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;
use Tests\Support\FakeTelegramClient;

/*
 * docs/35 O1: the Telegram base of the polls - `sendPhotoMessage()` (a file as multipart, a `file_id` / URL as a form field, the
 * caption and the keyboard; the `message_id` and the `file_id` of the largest PhotoSize back), `sendTextMessage()` and
 * `editMessageCaption()` with its "message is not modified" mark, and the refusals at the TelegramClient boundary (403 and
 * "chat not found", 429 with `retry_after`, a definite 400, a timeout). Every call goes through the SDK's `post()`, which the
 * double records and answers with the very bodies the Bot API sends.
 */

const POLL_API_TOKEN = '123456789:AAHpollpollpollpollpollpollpollpo0042';

beforeEach(function (): void {
    Cache::flush();
});

/**
 * An SDK whose every `post()` is recorded and takes the next scripted outcome: a Throwable is thrown, a string is the JSON body.
 * A multipart stream is read while recording (the upload happens at the call).
 *
 * @param  list<Throwable|string>  $script
 */
function pollApiSdk(array $script): Api
{
    return new class($script) extends Api
    {
        /** @var list<array{endpoint: string, params: array<array-key, mixed>, upload: bool}> */
        public array $calls = [];

        /** @param list<Throwable|string> $script */
        public function __construct(private array $script)
        {
            parent::__construct(POLL_API_TOKEN);
        }

        /** @param array<array-key, mixed> $params */
        public function post(string $endpoint, array $params = [], bool $fileUpload = false): TelegramResponse
        {
            if ($fileUpload) {
                foreach ($params as $i => $part) {
                    if (is_array($part) && ($part['contents'] ?? null) instanceof StreamInterface) {
                        $params[$i]['contents'] = 'stream:'.$part['contents']->getContents();
                    }
                }
            }
            $this->calls[] = ['endpoint' => $endpoint, 'params' => $params, 'upload' => $fileUpload];

            $outcome = array_shift($this->script) ?? '{"ok":true,"result":true}';
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return new TelegramResponse(new TelegramRequest(POLL_API_TOKEN, 'POST', $endpoint, $params), new Response(200, [], $outcome));
        }
    };
}

function pollApiClient(Api $sdk): IrazasyedTelegramClient
{
    return new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);
}

/** @param array<string, mixed> $body */
function pollApiRefusal(int $status, array $body, string $endpoint = 'sendPhoto'): TelegramResponseException
{
    return new TelegramResponseException(new TelegramResponse(
        new TelegramRequest(POLL_API_TOKEN, 'POST', $endpoint),
        new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)),
    ));
}

function pollApiTimeout(): TelegramSDKException
{
    $url = 'https://api.telegram.org/bot'.POLL_API_TOKEN.'/sendPhoto';
    $guzzle = new ConnectException("cURL error 28: Operation timed out after 30001 milliseconds for {$url}", new Request('POST', $url));

    return new TelegramSDKException($guzzle->getMessage(), 0, $guzzle);
}

/** The answer of a sent photo: Telegram's sizes, the largest NOT last, to prove the order is not relied on. */
function pollPhotoAnswer(int $messageId = 4242): string
{
    return json_encode(['ok' => true, 'result' => [
        'message_id' => $messageId,
        'chat' => ['id' => 555, 'type' => 'private'],
        'photo' => [
            ['file_id' => 'AgAD-small', 'file_unique_id' => 's', 'width' => 90, 'height' => 60, 'file_size' => 1_200],
            ['file_id' => 'AgAD-large', 'file_unique_id' => 'l', 'width' => 1280, 'height' => 853, 'file_size' => 98_000],
            ['file_id' => 'AgAD-medium', 'file_unique_id' => 'm', 'width' => 320, 'height' => 213, 'file_size' => 14_000],
        ],
        'caption' => 'Q?',
    ]], JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function pollKeyboard(): array
{
    return ['inline_keyboard' => [[['text' => 'Да', 'callback_data' => 'pl1:Ab3dEf6hIj9k:0']], [['text' => 'Нет', 'callback_data' => 'pl1:Ab3dEf6hIj9k:1']]]];
}

function pollCaught(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('expected an exception');
}

// --- sendPhotoMessage --------------------------------------------------------------------------------------------------

it('uploads a local photo as multipart with the caption and the keyboard, and answers the message id and the largest file_id', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'poll').'.png';
    file_put_contents($path, 'PNG-BYTES');
    $sdk = pollApiSdk([pollPhotoAnswer()]);

    try {
        $sent = pollApiClient($sdk)->sendPhotoMessage(555, PhotoSource::file($path), 'Какой формат удобнее?', pollKeyboard());
    } finally {
        @unlink($path);
    }

    expect($sent)->toEqual(new SentMessage(4242, 'AgAD-large'))
        ->and($sdk->calls)->toHaveCount(1)
        ->and($sdk->calls[0]['endpoint'])->toBe('sendPhoto')
        ->and($sdk->calls[0]['upload'])->toBeTrue()
        ->and($sdk->calls[0]['params'])->toBe([
            ['name' => 'photo', 'contents' => 'stream:PNG-BYTES', 'filename' => basename($path)],
            ['name' => 'chat_id', 'contents' => '555'],
            ['name' => 'caption', 'contents' => 'Какой формат удобнее?'],
            ['name' => 'reply_markup', 'contents' => '{"inline_keyboard":[[{"text":"Да","callback_data":"pl1:Ab3dEf6hIj9k:0"}],[{"text":"Нет","callback_data":"pl1:Ab3dEf6hIj9k:1"}]]}'],
        ]);
});

it('sends a photo by file_id as a form field, without any upload and without parse_mode', function (): void {
    $sdk = pollApiSdk([pollPhotoAnswer(77)]);

    $sent = pollApiClient($sdk)->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V'), 'Вопрос');

    expect($sent->messageId)->toBe(77)
        ->and($sent->photoFileId)->toBe('AgAD-large')
        ->and($sdk->calls[0]['upload'])->toBeFalse()
        ->and($sdk->calls[0]['params'])->toBe(['photo' => 'AgACAgIAAxkDAAIBQ2V', 'chat_id' => 555, 'caption' => 'Вопрос']);
});

it('sends a photo by URL as a form field, with no caption and no keyboard unless given', function (): void {
    $sdk = pollApiSdk([pollPhotoAnswer()]);

    pollApiClient($sdk)->sendPhotoMessage(555, PhotoSource::url('https://cdn.example.test/poll.jpg'));

    expect($sdk->calls[0]['upload'])->toBeFalse()
        ->and($sdk->calls[0]['params'])->toBe(['photo' => 'https://cdn.example.test/poll.jpg', 'chat_id' => 555]);
});

it('refuses an unreadable file before calling Telegram', function (): void {
    $sdk = pollApiSdk([]);

    expect(fn () => pollApiClient($sdk)->sendPhotoMessage(555, PhotoSource::file('/nonexistent/poll.png')))
        ->toThrow(TelegramApiException::class, 'not readable')
        ->and($sdk->calls)->toBe([]);
});

it('treats an ok answer without a message_id as "may have been sent", not as a refusal', function (): void {
    $e = pollCaught(fn () => pollApiClient(pollApiSdk(['{"ok":true,"result":true}']))->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V')));

    expect($e)->toBeInstanceOf(TelegramApiException::class)
        ->and($e->getMessage())->toContain('no message_id')
        ->and($e instanceof TelegramApiException && $e->isApiResponse())->toBeFalse();
});

it('answers no file_id when the message carries no photo sizes', function (): void {
    $sent = pollApiClient(pollApiSdk(['{"ok":true,"result":{"message_id":9,"photo":[{"width":10,"height":10}]}}']))
        ->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V'));

    expect($sent)->toEqual(new SentMessage(9, null));
});

it('classifies the refusals of a photo like every send', function (Closure $answer, string $class, ?string $reason): void {
    $e = pollCaught(fn () => pollApiClient(pollApiSdk([$answer()]))->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V')));

    expect($e)->toBeInstanceOf($class);
    if ($reason !== null) {
        expect($e instanceof TelegramForbiddenException ? $e->reason : null)->toBe($reason);
    }
})->with([
    '403 blocked' => [fn () => pollApiRefusal(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']), TelegramForbiddenException::class, TelegramForbiddenException::REASON_BOT_BLOCKED],
    '400 chat not found' => [fn () => pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']), TelegramForbiddenException::class, TelegramForbiddenException::REASON_CHAT_NOT_FOUND],
]);

it('turns a 429 into retry_after and pauses the outbound budget', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 25, windowSeconds: 1);
    $client = new IrazasyedTelegramClient(pollApiSdk([pollApiRefusal(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 17', 'parameters' => ['retry_after' => 17]])]), new TelegramKeyboardFactory, $limiter);

    $e = pollCaught(fn () => $client->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V')));

    expect($e)->toBeInstanceOf(TelegramRateLimitedException::class)
        ->and($e instanceof TelegramRateLimitedException ? $e->retryAfter : null)->toBe(17)
        ->and($e->getMessage())->toContain('sendPhoto')
        // The bot-wide budget is paused already: no background send gets a slot until `retry_after` is over.
        ->and($limiter->reserveGlobal())->toBeFalse();
});

it('keeps a definite refusal apart from a timeout, and never leaks the token', function (): void {
    $refused = pollCaught(fn () => pollApiClient(pollApiSdk([pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: wrong file identifier/HTTP URL specified'])]))
        ->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V')));
    $timedOut = pollCaught(fn () => pollApiClient(pollApiSdk([pollApiTimeout()]))
        ->sendPhotoMessage(555, PhotoSource::fileId('AgACAgIAAxkDAAIBQ2V')));

    expect($refused)->toBeInstanceOf(TelegramApiException::class)
        ->and($refused)->not->toBeInstanceOf(TelegramRateLimitedException::class)
        ->and($refused instanceof TelegramApiException && $refused->isApiResponse())->toBeTrue()
        ->and($timedOut)->toBeInstanceOf(TelegramApiException::class)
        ->and($timedOut instanceof TelegramApiException && $timedOut->isApiResponse())->toBeFalse()
        ->and($timedOut->getMessage())->not->toContain(POLL_API_TOKEN)
        ->and($timedOut->getPrevious())->toBeNull();
});

it('follows a group that became a supergroup and uploads the file again into the new chat', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'poll').'.jpg';
    file_put_contents($path, 'JPEG');
    $migrated = pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -100_99]]);
    $sdk = pollApiSdk([$migrated, pollPhotoAnswer(5)]);

    try {
        $sent = pollApiClient($sdk)->sendPhotoMessage(-42, PhotoSource::file($path));
    } finally {
        @unlink($path);
    }

    expect($sent->messageId)->toBe(5)
        ->and(array_map(fn (array $call): array => [$call['params'][0]['contents'], $call['params'][1]['contents']], $sdk->calls))
        ->toBe([['stream:JPEG', '-42'], ['stream:JPEG', '-10099']]);
});

// --- sendTextMessage ---------------------------------------------------------------------------------------------------

it('sends a plain text message at once and answers its id without a photo', function (): void {
    $sdk = pollApiSdk(['{"ok":true,"result":{"message_id":31,"chat":{"id":555}}}']);
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60);

    $sent = (new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, $limiter))->sendTextMessage(555, 'Вопрос?', pollKeyboard());

    expect($sent)->toEqual(new SentMessage(31))
        ->and($sdk->calls[0]['endpoint'])->toBe('sendMessage')
        ->and($sdk->calls[0]['params'])->toBe([
            'chat_id' => 555,
            'text' => 'Вопрос?',
            'reply_markup' => '{"inline_keyboard":[[{"text":"Да","callback_data":"pl1:Ab3dEf6hIj9k:0"}],[{"text":"Нет","callback_data":"pl1:Ab3dEf6hIj9k:1"}]]}',
        ])
        // No chat slot was taken and nothing deferred: the calling job holds the budget itself.
        ->and($limiter->reserve(555))->toBe(0);
});

it('classifies the refusals of a text message', function (): void {
    $blocked = pollCaught(fn () => pollApiClient(pollApiSdk([pollApiRefusal(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: user is deactivated'], 'sendMessage')]))->sendTextMessage(555, 'Hi'));
    $limited = pollCaught(fn () => pollApiClient(pollApiSdk([pollApiRefusal(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 4', 'parameters' => ['retry_after' => 4]], 'sendMessage')]))->sendTextMessage(555, 'Hi'));
    $noId = pollCaught(fn () => pollApiClient(pollApiSdk(['{"ok":true,"result":true}']))->sendTextMessage(555, 'Hi'));

    expect($blocked)->toBeInstanceOf(TelegramForbiddenException::class)
        ->and($limited instanceof TelegramRateLimitedException ? $limited->retryAfter : null)->toBe(4)
        ->and($noId instanceof TelegramApiException && ! $noId->isApiResponse())->toBeTrue();
});

// --- editMessageCaption ------------------------------------------------------------------------------------------------

it('edits a caption with a new keyboard, and removes the keyboard when none is given', function (): void {
    $sdk = pollApiSdk([pollPhotoAnswer(), pollPhotoAnswer()]);
    $client = pollApiClient($sdk);

    $client->editMessageCaption(555, 4242, "Вопрос\n\n✅ Спасибо! Ваш ответ: Да", ['inline_keyboard' => [[['text' => 'Изменить ответ', 'callback_data' => 'pl1:Ab3dEf6hIj9k:c']]]]);
    $client->editMessageCaption(555, 4242, 'Вопрос');

    expect(array_column($sdk->calls, 'endpoint'))->toBe(['editMessageCaption', 'editMessageCaption'])
        ->and($sdk->calls[0]['params'])->toBe([
            'chat_id' => 555,
            'message_id' => 4242,
            'caption' => "Вопрос\n\n✅ Спасибо! Ваш ответ: Да",
            'reply_markup' => '{"inline_keyboard":[[{"text":"Изменить ответ","callback_data":"pl1:Ab3dEf6hIj9k:c"}]]}',
        ])
        ->and($sdk->calls[1]['params']['reply_markup'])->toBe('{"inline_keyboard":[]}');
});

it('marks "message is not modified" of a caption edit, and only that one; a refused edit is never "unreachable"', function (): void {
    $sdk = pollApiSdk([
        pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same'], 'editMessageCaption'),
        pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: message can't be edited"], 'editMessageCaption'),
        pollApiRefusal(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 'editMessageCaption'),
    ]);
    $client = pollApiClient($sdk);

    $notModified = pollCaught(fn () => $client->editMessageCaption(555, 1, 'Same'));
    $tooOld = pollCaught(fn () => $client->editMessageCaption(555, 1, 'Old'));
    $blocked = pollCaught(fn () => $client->editMessageCaption(555, 1, 'Blocked'));

    expect($notModified instanceof TelegramApiException && $notModified->isMessageNotModified())->toBeTrue()
        ->and($tooOld instanceof TelegramApiException && ! $tooOld->isMessageNotModified() && $tooOld->isApiResponse())->toBeTrue()
        ->and($blocked)->toBeInstanceOf(TelegramApiException::class)
        ->and($blocked)->not->toBeInstanceOf(TelegramForbiddenException::class);
});

it('gives a 429 of a caption edit its retry_after, and passes a migration on', function (): void {
    $sdk = pollApiSdk([
        pollApiRefusal(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 2', 'parameters' => ['retry_after' => 2]], 'editMessageCaption'),
        pollApiRefusal(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -100_7]], 'editMessageCaption'),
    ]);
    $client = pollApiClient($sdk);

    $limited = pollCaught(fn () => $client->editMessageCaption(555, 1, 'A'));
    $migrated = pollCaught(fn () => $client->editMessageCaption(-42, 1, 'B'));

    expect($limited instanceof TelegramRateLimitedException ? $limited->retryAfter : null)->toBe(2)
        ->and($migrated)->toBeInstanceOf(TelegramChatMigratedException::class)
        // An edit is not repeated in the new chat: the message id of the old chat means nothing there.
        ->and($sdk->calls)->toHaveCount(2);
});

// --- PhotoSource / SentMessage -----------------------------------------------------------------------------------------

it('builds the three kinds of a photo source and refuses empty or non-http ones', function (): void {
    expect(PhotoSource::file('/tmp/a.png')->isUpload())->toBeTrue()
        ->and(PhotoSource::fileId(' AgAC ')->value)->toBe('AgAC')
        ->and(PhotoSource::fileId('AgAC')->kind)->toBe(PhotoSource::KIND_FILE_ID)
        ->and(PhotoSource::url('https://x.test/a.jpg')->kind)->toBe(PhotoSource::KIND_URL)
        ->and(fn () => PhotoSource::file(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PhotoSource::fileId('  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PhotoSource::url('file:///etc/passwd'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PhotoSource::url('not a url'))->toThrow(InvalidArgumentException::class);
});

it('takes the largest photo size by area, the bigger file on a tie', function (): void {
    $sent = SentMessage::fromMessage(['message_id' => 1, 'photo' => [
        ['file_id' => 'a', 'width' => 100, 'height' => 100, 'file_size' => 10],
        ['file_id' => 'b', 'width' => 100, 'height' => 100, 'file_size' => 20],
        ['file_id' => 'c', 'width' => 50, 'height' => 50, 'file_size' => 99],
    ]], 'sendPhoto');

    expect($sent->photoFileId)->toBe('b')
        ->and(fn () => SentMessage::fromMessage(['message_id' => 0], 'sendPhoto'))->toThrow(TelegramApiException::class);
});

// --- the fake --------------------------------------------------------------------------------------------------------

it('records poll sends and caption edits in the fake, with controlled refusals', function (): void {
    $fake = new FakeTelegramClient;

    $first = $fake->sendPhotoMessage(555, PhotoSource::file('/tmp/poll.png'), 'Q', pollKeyboard());
    $again = $fake->sendPhotoMessage(556, PhotoSource::fileId((string) $first->photoFileId), 'Q');
    $text = $fake->sendTextMessage(557, 'Q', pollKeyboard());
    $fake->editMessageCaption(555, $first->messageId, 'Q ✅');

    expect($first)->toEqual(new SentMessage(1001, 'fake-photo-1001'))
        ->and($again)->toEqual(new SentMessage(1002, 'fake-photo-1001'))
        ->and($text)->toEqual(new SentMessage(1003))
        ->and(array_column($fake->photoMessages, 'kind'))->toBe([PhotoSource::KIND_FILE, PhotoSource::KIND_FILE_ID])
        ->and($fake->textMessages[0]['markup'])->toBe(pollKeyboard())
        ->and($fake->captionEdits)->toBe([['chat_id' => 555, 'message_id' => 1001, 'caption' => 'Q ✅', 'markup' => null]]);

    $fake->failSends = 1;
    $fake->failSendsRetryAfter = 6;
    $limited = pollCaught(fn () => $fake->sendPhotoMessage(555, PhotoSource::fileId('x')));
    $fake->failSends = 1;
    $fake->failSendsRetryAfter = null;
    $fake->failSendsForbidden = TelegramForbiddenException::REASON_BOT_BLOCKED;
    $blocked = pollCaught(fn () => $fake->sendTextMessage(555, 'Q'));
    $fake->failCaptionEdits = true;
    $notModified = pollCaught(fn () => $fake->editMessageCaption(555, 1001, 'Q'));
    $fake->failCaptionEdits = false;
    $fake->failCaptionEditsWith = "message can't be edited";
    $tooOld = pollCaught(fn () => $fake->editMessageCaption(555, 1001, 'Q'));

    expect($limited instanceof TelegramRateLimitedException ? [$limited->retryAfter, $limited->getMessage()] : null)->toBe([6, 'Telegram API rate limit (429) on sendPhoto: retry after 6 s'])
        ->and($blocked)->toBeInstanceOf(TelegramForbiddenException::class)
        ->and($notModified instanceof TelegramApiException && $notModified->isMessageNotModified())->toBeTrue()
        ->and($tooOld instanceof TelegramApiException && ! $tooOld->isMessageNotModified())->toBeTrue()
        // Failed calls are not recorded and take no message id.
        ->and($fake->photoMessages)->toHaveCount(2)
        ->and($fake->textMessages)->toHaveCount(1)
        ->and($fake->nextMessageId)->toBe(1004);
});
