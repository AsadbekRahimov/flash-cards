<?php

declare(strict_types=1);

use App\Domain\Operations\Support\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

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

it('is registered as before_send hook in config/sentry.php', function (): void {
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'beforeSendTransaction'])
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.dsn'))->toBeEmpty();
});
