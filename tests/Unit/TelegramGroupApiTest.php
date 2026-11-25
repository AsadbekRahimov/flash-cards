<?php

declare(strict_types=1);

use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Objects\ChatMember;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;
use Tests\Support\FakeTelegramClient;

/*
 * docs/33 K1: the Telegram base of the cup - `getChatMember()` with `is_member`, `sendGroupMessage()` that posts at once and returns
 * the `message_id` (forum topic, silent), and the "message is not modified" mark of an edit refusal. The SDK double answers with the
 * very bodies the Bot API sends; the SDK wraps the whole body (`{ok, result}`) into the returned object.
 */

const GROUP_API_TOKEN = '123456789:AAHgroupgroupgroupgroupgroupgroup0042';

beforeEach(function (): void {
    Cache::flush();
    config(['telegram.bot_token' => GROUP_API_TOKEN]);
});

/** @param array<string, mixed> $body */
function groupApiAnswer(int $status, array $body, string $endpoint = 'sendMessage'): TelegramResponseException
{
    return new TelegramResponseException(new TelegramResponse(
        new TelegramRequest(GROUP_API_TOKEN, 'POST', $endpoint),
        new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR)),
    ));
}

/**
 * An SDK whose every call takes the next scripted outcome: a Throwable is thrown, an array is the decoded body of the answer.
 *
 * @param  list<Throwable|array<string, mixed>>  $script
 */
function groupApiSdk(array $script): Api
{
    return new class($script) extends Api
    {
        /** @var list<array{method: string, params: array<string, mixed>}> */
        public array $calls = [];

        /** @param list<Throwable|array<string, mixed>> $script */
        public function __construct(private array $script)
        {
            parent::__construct(GROUP_API_TOKEN);
        }

        /**
         * @param  array<string, mixed>  $params
         * @return array<string, mixed>
         */
        private function next(string $method, array $params): array
        {
            $this->calls[] = ['method' => $method, 'params' => $params];
            $outcome = array_shift($this->script);
            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome ?? [];
        }

        /** @param array<string, mixed> $params */
        public function sendMessage(array $params): MessageObject
        {
            return new MessageObject($this->next('sendMessage', $params));
        }

        /** @param array<string, mixed> $params */
        public function getChatMember(array $params): ChatMember
        {
            return new ChatMember($this->next('getChatMember', $params));
        }

        /** @param array<string, mixed> $params */
        public function editMessageText(array $params): MessageObject
        {
            return new MessageObject($this->next('editMessageText', $params));
        }
    };
}

function groupApiClient(Api $sdk): IrazasyedTelegramClient
{
    return new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);
}

it('reads the status and is_member of a chat member', function (array $member, string $status, bool $isMember): void {
    $client = groupApiClient(groupApiSdk([['ok' => true, 'result' => $member]]));

    $info = $client->getChatMember(-100_42, 7);

    expect($info)->toEqual(new ChatMemberInfo($status, $isMember));
})->with([
    'creator' => [['status' => 'creator', 'user' => ['id' => 7]], 'creator', true],
    'administrator' => [['status' => 'administrator', 'user' => ['id' => 7]], 'administrator', true],
    'member' => [['status' => 'member', 'user' => ['id' => 7]], 'member', true],
    'restricted, still in the chat' => [['status' => 'restricted', 'is_member' => true], 'restricted', true],
    'restricted, left the chat' => [['status' => 'restricted', 'is_member' => false], 'restricted', false],
    'left' => [['status' => 'left'], 'left', false],
    'kicked' => [['status' => 'kicked', 'until_date' => 0], 'kicked', false],
    'an answer without a status' => [[], '', false],
]);

it('keeps getChatMemberStatus as the status of getChatMember', function (): void {
    $sdk = groupApiSdk([['ok' => true, 'result' => ['status' => 'restricted', 'is_member' => false]]]);

    expect(groupApiClient($sdk)->getChatMemberStatus(-100_42, 7))->toBe('restricted')
        ->and($sdk->calls[0]['params'])->toBe(['chat_id' => -100_42, 'user_id' => 7]);
});

it('posts a group message at once and returns its message_id, with the topic and silently', function (): void {
    $sdk = groupApiSdk([['ok' => true, 'result' => ['message_id' => 321, 'chat' => ['id' => -100_42]]]]);
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, $limiter);

    $id = $client->sendGroupMessage(-100_42, '<b>Cup</b>', 'HTML', ['inline_keyboard' => [[['text' => 'Play', 'url' => 'https://t.me/x']]]], threadId: 17, disableNotification: true);

    expect($id)->toBe(321)
        ->and($sdk->calls[0]['method'])->toBe('sendMessage')
        ->and($sdk->calls[0]['params'])->toBe([
            'chat_id' => -100_42,
            'text' => '<b>Cup</b>',
            'parse_mode' => 'HTML',
            'reply_markup' => '{"inline_keyboard":[[{"text":"Play","url":"https://t.me/x"}]]}',
            'message_thread_id' => 17,
            'disable_notification' => true,
        ]);
    // No slot of the chat was taken: the calling job reserves it itself (and nothing was deferred into the queue).
    expect($limiter->reserve(-100_42))->toBe(0);
});

it('sends no topic and no silence unless asked', function (): void {
    $sdk = groupApiSdk([['ok' => true, 'result' => ['message_id' => 5]]]);

    expect(groupApiClient($sdk)->sendGroupMessage(-100_42, 'Hi'))->toBe(5)
        ->and($sdk->calls[0]['params'])->toBe(['chat_id' => -100_42, 'text' => 'Hi']);
});

it('treats an ok answer without a message_id as "may have been posted", not as a refusal', function (): void {
    $client = groupApiClient(groupApiSdk([['ok' => true, 'result' => true]]));

    try {
        $client->sendGroupMessage(-100_42, 'Hi');
        $this->fail('expected an exception');
    } catch (TelegramApiException $e) {
        expect($e->isApiResponse())->toBeFalse();
    }
});

it('classifies the refusals of a group message like every send', function (Throwable $answer, string $class): void {
    $client = groupApiClient(groupApiSdk([$answer]));

    expect(fn () => $client->sendGroupMessage(-100_42, 'Hi'))->toThrow($class);
})->with([
    '403 bot was kicked' => [fn () => groupApiAnswer(403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was kicked from the supergroup chat']), TelegramForbiddenException::class],
    '429' => [fn () => groupApiAnswer(429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 9', 'parameters' => ['retry_after' => 9]]), TelegramRateLimitedException::class],
]);

it('follows a group that became a supergroup and returns the id of the message in the new chat', function (): void {
    $migrated = groupApiAnswer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -100_99]]);
    $sdk = groupApiSdk([$migrated, ['ok' => true, 'result' => ['message_id' => 77]]]);

    expect(groupApiClient($sdk)->sendGroupMessage(-42, 'Hi'))->toBe(77)
        ->and(array_column(array_column($sdk->calls, 'params'), 'chat_id'))->toBe([-42, -100_99]);
});

it('marks the refusal "message is not modified" of an edit, and only that one', function (): void {
    $sdk = groupApiSdk([
        groupApiAnswer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same'], 'editMessageText'),
        groupApiAnswer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 'editMessageText'),
    ]);
    $client = groupApiClient($sdk);

    $notModified = null;
    $notFound = null;
    try {
        $client->editMessageText(-100_42, 5, 'Same');
    } catch (TelegramApiException $e) {
        $notModified = $e;
    }
    try {
        $client->editMessageText(-100_42, 5, 'Gone');
    } catch (TelegramApiException $e) {
        $notFound = $e;
    }

    expect($notModified?->isMessageNotModified())->toBeTrue()
        ->and($notModified?->isApiResponse())->toBeTrue()
        ->and($notFound?->isMessageNotModified())->toBeFalse()
        ->and(TelegramApiException::apiError('Bad Request: message is not modified')->isMessageNotModified())->toBeTrue()
        ->and((new TelegramApiException('cURL error 28'))->isMessageNotModified())->toBeFalse();
});

it('gives the fake client the same two methods for the tests of the cup', function (): void {
    $fake = new FakeTelegramClient;

    expect($fake->sendGroupMessage(-100_42, 'One', threadId: 3, disableNotification: true))->toBe(1001)
        ->and($fake->sendGroupMessage(-100_42, 'Two'))->toBe(1002)
        ->and($fake->groupMessages[0])->toMatchArray(['chat_id' => -100_42, 'text' => 'One', 'thread_id' => 3, 'silent' => true, 'message_id' => 1001])
        ->and($fake->getChatMember(-100_42, 7))->toEqual(new ChatMemberInfo('administrator', true));

    $fake->chatMember = new ChatMemberInfo('restricted', false);
    expect($fake->getChatMember(-100_42, 7)->isMember)->toBeFalse();

    $fake->failSends = 1;
    $fake->failSendsRetryAfter = 2;
    expect(fn () => $fake->sendGroupMessage(-100_42, 'Three'))->toThrow(TelegramRateLimitedException::class);
});
