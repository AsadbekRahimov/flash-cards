<?php

declare(strict_types=1);

use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
 * A deferred message (SendTelegramMessageJob) that Telegram forbids (docs/34 §4в п. 8, B3 / B4): the job ends without an error, the
 * chat id stays out of the log, and the recipient is marked - a private chat closes the learner's DM, a group goes `disabled`.
 * A 429 still leaves the job (the middleware puts it back for `retry_after`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

function forbiddingClient(string $reason = TelegramForbiddenException::REASON_BOT_BLOCKED): IrazasyedTelegramClient
{
    $telegram = Mockery::mock(IrazasyedTelegramClient::class);
    $telegram->shouldReceive('sendImmediately')->once()->andThrow(new TelegramForbiddenException($reason));

    return $telegram;
}

it('does not retry a deferred message Telegram forbids and keeps the chat id out of the log', function (): void {
    Log::spy();

    (new SendTelegramMessageJob(-1001234567, 'Deferred message'))->handle(forbiddingClient(), new TelegramMessageRateLimiter(maxMessages: 5, windowSeconds: 60));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'telegram.deferred_message_forbidden'
            && $context['reason'] === TelegramForbiddenException::REASON_BOT_BLOCKED
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), '1001234567'))
        ->once();
});

it('closes the DM of the learner on a 403 in a private chat, with the reason', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7_100_001]);

    (new SendTelegramMessageJob(7_100_001, 'Deferred'))->handle(forbiddingClient(TelegramForbiddenException::REASON_USER_DEACTIVATED), new TelegramMessageRateLimiter);

    expect($learner->fresh()?->dm_blocked_at)->not->toBeNull()
        ->and($learner->fresh()?->dm_blocked_reason)->toBe('user_deactivated');
});

it('turns the group disabled on a 403 in a group chat, through Eloquent', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -100_555, 'status' => 'active']);
    $other = TelegramGroup::factory()->create(['chat_id' => -100_556, 'status' => 'active']);

    (new SendTelegramMessageJob('-100555', 'Deferred'))->handle(forbiddingClient(), new TelegramMessageRateLimiter);

    expect($group->fresh()?->status)->toBe('disabled')
        ->and($other->fresh()?->status)->toBe('active');
});

it('touches nobody for a @channel name', function (): void {
    $learner = Learner::factory()->create();

    (new SendTelegramMessageJob('@somechannel', 'Deferred'))->handle(forbiddingClient(), new TelegramMessageRateLimiter);

    expect($learner->fresh()?->dm_blocked_at)->toBeNull();
});

it('lets a 429 out of the job (the middleware puts it back) and marks nobody', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 7_100_002]);
    $telegram = Mockery::mock(IrazasyedTelegramClient::class);
    $telegram->shouldReceive('sendImmediately')->once()->andThrow(new TelegramRateLimitedException(5, 'sendMessage'));

    expect(fn () => (new SendTelegramMessageJob(7_100_002, 'Deferred'))->handle($telegram, new TelegramMessageRateLimiter))
        ->toThrow(TelegramRateLimitedException::class);
    expect($learner->fresh()?->dm_blocked_at)->toBeNull();
});
