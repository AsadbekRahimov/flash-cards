<?php

declare(strict_types=1);

use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

it('limits each chat with an atomic sliding window', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 2, windowSeconds: 60);

    expect($limiter->reserve(-1001))->toBe(0);
    expect($limiter->reserve(-1001))->toBe(0);
    expect($limiter->reserve(-1001))->toBe(60);

    $this->travel(61)->seconds();

    expect($limiter->reserve(-1001))->toBe(0);
});

it('keeps independent windows for different chats', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60);

    expect($limiter->reserve(-1001))->toBe(0);
    expect($limiter->reserve(-2002))->toBe(0);
});

it('releases the same deferred job when the chat limit is still exhausted', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60);
    $limiter->reserve(-1001);

    $telegram = Mockery::mock(IrazasyedTelegramClient::class);
    $telegram->shouldNotReceive('sendImmediately');

    $job = new SendTelegramMessageJob(-1001, 'Deferred message');
    $job->handle($telegram, $limiter);
});

it('sends a deferred message immediately after reserving its queue slot', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 1, windowSeconds: 60);
    $telegram = Mockery::mock(IrazasyedTelegramClient::class);
    $telegram->shouldReceive('sendImmediately')
        ->once()
        ->with(-1001, 'Deferred message', null, null);

    $job = new SendTelegramMessageJob(-1001, 'Deferred message');
    $job->handle($telegram, $limiter);
});

// A deferred message Telegram forbids (403): tests/Feature/Telegram/DeferredMessageForbiddenTest.php (it writes the database).

it('still fails a deferred message on other Telegram errors so the queue retries it', function (): void {
    $limiter = new TelegramMessageRateLimiter(maxMessages: 5, windowSeconds: 60);
    $telegram = Mockery::mock(IrazasyedTelegramClient::class);
    $telegram->shouldReceive('sendImmediately')->once()->andThrow(new RuntimeException('Telegram is down'));

    $job = new SendTelegramMessageJob(-1001, 'Deferred message');

    expect(fn () => $job->handle($telegram, $limiter))->toThrow(RuntimeException::class, 'Telegram is down');
});
