<?php

declare(strict_types=1);

use App\Domain\Operations\Support\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

const SCRUB_BOT_TOKEN = '123456789:AAEhBP0av18UbLNvPLhm4RxQ0l7hTqlJkP8';
const SCRUB_WEBHOOK_SECRET = 'whsec_AbCdEf0123456789xyz';

function scrubbedJson(Event $event): string
{
    return json_encode([
        'request' => $event->getRequest(),
        'message' => $event->getMessage(),
        'transaction' => $event->getTransaction(),
        'exceptions' => array_map(fn (ExceptionDataBag $e): string => $e->getValue(), $event->getExceptions()),
        'breadcrumbs' => array_map(fn (Breadcrumb $b): array => [$b->getMessage(), $b->getMetadata()], $event->getBreadcrumbs()),
        'extra' => $event->getExtra(),
        'tags' => $event->getTags(),
    ], JSON_THROW_ON_ERROR);
}

it('removes Authorization header, cookies and init_data from the request', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://lexiflow.test/api/twa/auth',
        'headers' => [
            'Authorization' => ['Bearer eyJhbGciOi.payload.signature'],
            'Cookie' => ['laravel_session=abc123'],
            'X-Telegram-Bot-Api-Secret-Token' => ['hdr-secret-value'],
            'User-Agent' => ['Mozilla/5.0'],
        ],
        'cookies' => ['laravel_session' => 'abc123'],
        'data' => ['init_data' => 'query_id=AAH&user=%7B%22id%22%3A1%7D&hash=deadbeef', 'context' => ['type' => 'training']],
    ]);

    $json = scrubbedJson(SentryScrubber::beforeSend($event));

    expect($json)->not->toContain('eyJhbGciOi')
        ->and($json)->not->toContain('laravel_session')
        ->and($json)->not->toContain('abc123')
        ->and($json)->not->toContain('hdr-secret-value')
        ->and($json)->not->toContain('query_id')
        ->and($json)->not->toContain('deadbeef')
        ->and($json)->toContain('Mozilla')
        ->and($json)->toContain('training');
});

it('redacts the webhook secret from URLs, query strings and the transaction name', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://lexiflow.test/telegram/webhook/'.SCRUB_WEBHOOK_SECRET.'?secret=queryleak&page=2',
        'query_string' => 'secret=queryleak&page=2',
    ]);
    $event->setTransaction('/telegram/webhook/'.SCRUB_WEBHOOK_SECRET);

    $scrubbed = SentryScrubber::beforeSendTransaction($event);
    $json = scrubbedJson($scrubbed);

    expect($json)->not->toContain(SCRUB_WEBHOOK_SECRET)
        ->and($json)->not->toContain('queryleak')
        ->and($scrubbed->getRequest()['url'])->toContain('/telegram/webhook/'.SentryScrubber::REDACTED)
        ->and($scrubbed->getRequest()['url'])->toContain('page=2');
});

it('redacts Telegram bot tokens from messages, exceptions, breadcrumbs and extra', function (): void {
    $url = 'https://api.telegram.org/bot'.SCRUB_BOT_TOKEN.'/sendMessage';

    $event = Event::createEvent();
    $event->setMessage("POST {$url} failed");
    $event->setExceptions([new ExceptionDataBag(new RuntimeException("cURL error for {$url}"))]);
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', $url, ['url' => $url, 'token' => SCRUB_BOT_TOKEN]),
    ]);
    $event->setExtra(['raw' => SCRUB_BOT_TOKEN, 'nested' => ['TELEGRAM_BOT_TOKEN' => SCRUB_BOT_TOKEN]]);

    $json = scrubbedJson(SentryScrubber::beforeSend($event));

    expect($json)->not->toContain('AAEhBP0av18UbLNvPLhm4RxQ0l7hTqlJkP8')
        ->and($json)->not->toContain('123456789:');
});

it('redacts configured secret values wherever they appear', function (): void {
    config([
        'telegram.url_secret' => 'configured-url-secret-1234',
        'telegram.header_secret' => 'configured-header-secret-5678',
    ]);

    $event = Event::createEvent();
    $event->setMessage('boom configured-url-secret-1234 and configured-header-secret-5678');

    $json = scrubbedJson(SentryScrubber::beforeSend($event));

    expect($json)->not->toContain('configured-url-secret-1234')
        ->and($json)->not->toContain('configured-header-secret-5678');
});

it('masks personal fields of Telegram update bodies via UpdateSanitizer', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'data' => [
            'update_id' => 10,
            'message' => [
                'text' => 'private message text',
                'from' => ['id' => 5, 'first_name' => 'Alexander', 'username' => 'alex_secret'],
            ],
        ],
    ]);

    $json = scrubbedJson(SentryScrubber::beforeSend($event));

    expect($json)->not->toContain('private message text')
        ->and($json)->not->toContain('Alexander')
        ->and($json)->not->toContain('alex_secret')
        ->and($json)->toContain('update_id');
});

it('drops stack frame arguments of exceptions and of the event stacktrace', function (): void {
    $leakyFrame = fn (): Frame => new Frame(
        'GuzzleHttp\Client::send',
        'vendor/guzzlehttp/guzzle/src/Client.php',
        10,
        null,
        null,
        [
            'https://api.telegram.org/bot'.SCRUB_BOT_TOKEN.'/sendMessage',
            ['chat_id' => 1, 'text' => 'Dear Alexander', 'init_data' => 'query_id=AAH&hash=deadbeef'],
        ],
    );

    $event = Event::createEvent();
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('timeout'), new Stacktrace([$leakyFrame(), $leakyFrame()])),
    ]);
    $event->setStacktrace(new Stacktrace([$leakyFrame()]));

    $scrubbed = SentryScrubber::beforeSend($event);

    $frames = [
        ...$scrubbed->getExceptions()[0]->getStacktrace()->getFrames(),
        ...$scrubbed->getStacktrace()->getFrames(),
    ];
    $json = json_encode(array_map(fn (Frame $f): array => [$f->getFunctionName(), $f->getVars()], $frames), JSON_THROW_ON_ERROR);

    expect($frames)->toHaveCount(3)
        ->and($json)->not->toContain('AAEhBP0av18')
        ->and($json)->not->toContain('Alexander')
        ->and($json)->not->toContain('query_id')
        ->and($json)->not->toContain('deadbeef')
        ->and($json)->toContain('GuzzleHttp');
    foreach ($frames as $frame) {
        expect($frame->getVars())->toBe([]);
    }
});

it('does not touch events without any stacktrace', function (): void {
    expect(SentryScrubber::beforeSend(Event::createEvent())->getStacktrace())->toBeNull();
});

it('masks password and 2FA codes inside Livewire JSON bodies', function (): void {
    $snapshot = json_encode(['data' => ['password' => 'hunter2-secret', 'code' => '123456', 'email' => 'a@b.c']], JSON_THROW_ON_ERROR);
    $event = Event::createEvent();
    $event->setRequest([
        'data' => [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['data.code' => '654321', 'data.recovery_code' => 'abcde-12345'],
                'calls' => [],
            ]],
            'wrapped' => json_encode(['snapshot' => $snapshot, 'recovery_code' => 'rec-98765', 'one_time_password' => 777888], JSON_THROW_ON_ERROR),
            'code' => '555444',
        ],
    ]);

    $json = scrubbedJson(SentryScrubber::beforeSend($event));

    expect($json)->not->toContain('hunter2-secret')
        ->and($json)->not->toContain('123456')
        ->and($json)->not->toContain('654321')
        ->and($json)->not->toContain('abcde-12345')
        ->and($json)->not->toContain('rec-98765')
        ->and($json)->not->toContain('777888')
        ->and($json)->not->toContain('555444')
        ->and($json)->toContain('a@b.c');
});

it('masks double-encoded JSON credentials', function (): void {
    $inner = json_encode(['password' => 'p@ss"word-leak', 'code' => '246810', 'name' => 'ok'], JSON_THROW_ON_ERROR);
    $outer = json_encode(['snapshot' => $inner], JSON_THROW_ON_ERROR);

    $scrubbed = SentryScrubber::scrubString($outer);

    expect($scrubbed)->not->toContain('word-leak')
        ->and($scrubbed)->not->toContain('246810')
        ->and($scrubbed)->toContain('ok');
});

it('is registered as before_send hook in config/sentry.php', function (): void {
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'beforeSendTransaction'])
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.dsn'))->toBeEmpty();
});
